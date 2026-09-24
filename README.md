# filament-prism

Registre de services IA (basés sur [`prism-php/prism`](https://prismphp.com))
pour Filament, avec persistance durable de chaque appel et suivi des tokens.

Le premier service livré est la **correction orthographique et grammaticale**
(tâche `orthography`) : d'autres tâches (traduction, reformulation, ...)
s'ajoutent sans toucher au cœur du package.

## Sommaire

- [Installation](#installation)
- [Concepts](#concepts)
  - [AiInteraction — la durabilité](#aiinteraction--la-durabilité)
  - [AiTask / AiTaskRegistry — le registre de tâches](#aitask--aitaskregistry--le-registre-de-tâches)
  - [CorrectionSubject — quoi corriger](#correctionsubject--quoi-corriger)
  - [CorrectionService — appeler l'IA et appliquer le résultat](#correctionservice--appeler-lia-et-appliquer-le-résultat)
  - [AiResultRenderer — comment afficher le résultat](#airesultrenderer--comment-afficher-le-résultat)
  - [Sans clé API — échec propre plutôt qu'un plantage](#sans-clé-api--échec-propre-plutôt-quun-plantage)
- [Utilisation : un modèle Eloquent (`Correctable`)](#utilisation--un-modèle-eloquent-correctable)
- [Utilisation : un texte hors modèle (`FieldsCorrectionSubject`)](#utilisation--un-texte-hors-modèle-fieldscorrectionsubject)
- [Utilisation : plusieurs sujets en un seul appel (`CorrectionSubjectGroup`)](#utilisation--plusieurs-sujets-en-un-seul-appel-correctionsubjectgroup)
- [Widgets de consommation](#widgets-de-consommation)
- [Roadmap (hors périmètre v1)](#roadmap-hors-périmètre-v1)

## Installation

Path-repository, comme les autres plugins `charlesstolive/filament-*` de ce
projet (voir `desapp/composer.json`, section `repositories`) :

```json
{
    "repositories": [
        {"type": "path", "url": "../packages_filament/filament-prism"}
    ],
    "require": {
        "charlesstolive/filament-prism": "dev-master",
        "prism-php/prism": "^0.99"
    }
}
```

```bash
sail composer update charlesstolive/filament-prism prism-php/prism
sail artisan vendor:publish --tag=filament-prism-migrations
sail artisan migrate
```

Le provider/modèle par défaut se règlent dans `config/filament-prism.php`
(publié automatiquement) ou par les variables d'environnement
`FILAMENT_PRISM_PROVIDER`/`FILAMENT_PRISM_MODEL` (à défaut, `AI_PROVIDER`/
`AI_MODEL`) — la clé API elle-même se configure côté `prism-php/prism`, dans
`config/prism.php` de l'application.

## Concepts

### `AiInteraction` — la durabilité

Chaque appel IA est persisté **dès sa réponse**, jamais tenu seulement en
état Livewire : une coupure de session entre l'appel et le clic sur
« Appliquer » ne perd ni le travail, ni le budget de tokens déjà dépensé.
Rouvrir l'action retrouve l'interaction `pending` existante au lieu de
rappeler l'IA — mais seulement si le texte envoyé est encore le même
(`CorrectionService::reusablePending()`) : si le sujet a été modifié à la
main entre-temps, cette vieille réponse ne correspond plus à rien de saisi
— elle passe `discarded` et l'appel repart à neuf (voir
`CorrectionService::correct()`).

Colonnes notables :

| Colonne | Rôle |
|---|---|
| `correctable_type`/`correctable_id` | Le modèle auquel l'interaction se rattache pour durer (`CorrectionSubject::model()`) — pas forcément le modèle qui porte le texte corrigé, voir plus bas. |
| `subject_key` | Distingue plusieurs sujets rattachés au même modèle (ex. plusieurs périodes d'un même voyage). `null` quand le modèle seul suffit à identifier le sujet. |
| `trackable_type`/`trackable_id` | Le périmètre d'agrégation pour les widgets de tokens (ex. le voyage entier), indépendant du modèle d'attache. |
| `input`/`output` | Les valeurs envoyées, et la réponse structurée de l'IA (déjà décodée par Prism, pas de regex). |
| `status` | `pending` → `applied`/`discarded`. Rien n'est supprimé au clic « ignorer » : l'historique reste consultable. |
| `thread_id`/`parent_interaction_id` | Posées maintenant, inutilisées en v1 (chaque interaction est un fil à elle seule) : Prism sait déjà rejouer un historique de messages (`withMessages()`), elles éviteront un `ALTER TABLE` le jour où un vrai dialogue multi-tours existera. |
| `meta` (json) | Ce dont un renderer a besoin sans connaître le `CorrectionSubject` d'origine (il ne survit pas à la requête qui a appelé l'IA) : `meta.labels` porte le libellé de chaque champ (`CorrectableField::labelsByName()`), utilisé par `TextDiffRenderer`/`GroupedTextDiffRenderer` — sans ça, `CorrectableField::make('body')->label('Contenu')` n'aurait aucun effet visible, la vue régénérant un libellé générique (`Str::headline($field)`) faute d'accès à la déclaration d'origine. Un futur `ChoiceRenderer` y noterait par exemple l'option choisie. |

### `AiTask` / `AiTaskRegistry` — le registre de tâches

Une tâche = une classe qui implémente `AiTask` (clé, prompt système,
provider/modèle par défaut, classe de renderer), listée dans
`config('filament-prism.tasks')`. `OrthographyTask` est la seule fournie ;
en ajouter une nouvelle ne touche pas au cœur du package :

```php
// config/filament-prism.php
'tasks' => [
    \CharlesStOlive\FilamentPrism\Tasks\OrthographyTask::class,
    \App\Ai\Tasks\TranslationTask::class, // exemple
],
```

### `CorrectionSubject` — quoi corriger

`CorrectionService::correct()` ne connaît que ce contrat — 4 méthodes :

```php
interface CorrectionSubject
{
    public function model(): Model;      // l'AiInteraction s'y rattache, pour durer
    public function key(): ?string;      // distingue plusieurs sujets d'un même modèle ; null si le modèle seul suffit
    public function fields(): array;     // CorrectableField[]
    public function extractValues(): array; // les valeurs actuelles à envoyer à l'IA
}
```

Deux implémentations livrées :

- **`Correctable`** (trait) — pour un texte qui vit dans les colonnes d'un
  modèle Eloquent. `model()` renvoie `$this`, `key()` reste `null` (le
  modèle seul identifie le sujet).
- **`FieldsCorrectionSubject`** — pour un texte qui vit ailleurs (un
  tableau d'état Livewire, par exemple). L'interaction se rattache tout de
  même à un modèle stable, mais la lecture des valeurs passe par une closure
  explicite, et `key()` devient obligatoire pour distinguer plusieurs sujets
  du même modèle.

`CorrectionSubject` ne sait dire que **quoi envoyer à l'IA**. Écrire le
résultat est une préoccupation séparée (voir `CorrectionService::apply()` et
`autoApply()` plus bas) — les deux cas n'écrivent pas de la même façon.

### `CorrectionService` — appeler l'IA et appliquer le résultat

```php
$interaction = app(CorrectionService::class)->correct($subject, taskKey: 'orthography', trackable: $voyage);

// Seulement valable quand $subject est un modèle Correctable (voir plus bas) :
app(CorrectionService::class)->apply($interaction, onlyFields: ['title']); // écrit + save()
app(CorrectionService::class)->discard($interaction);                     // status = discarded, rien n'est effacé
```

### `AiResultRenderer` — comment afficher le résultat

Chaque `AiTask` déclare son `rendererClass()`. `OrthographyTask` utilise
`TextDiffRenderer` : un diff mot-à-mot par champ (`WordDiff`, pur PHP, sans
lib JS), affiché en deux blocs côte à côte — « Avant » (rouge, les mots
retirés surlignés) et « Après » (vert, les mots ajoutés surlignés), plutôt
qu'un diff en ligne mêlant barré et souligné. Le texte comparé est extrait
par `WordDiff::textOf()` : fins de bloc (`</p>`, `<br>`…) en sauts de ligne
(sinon deux paragraphes se collent), balises retirées, **puis** entités
décodées — sans ce décodage, une entité déjà présente dans le HTML source
(`&#039;`) ressortirait échappée une seconde fois par Blade (`&amp;#039;`,
visible en toutes lettres à l'écran).

Le style de ces blocs est une feuille CSS propre au package
(`resources/css/filament-prism.css`, déclarée comme asset Filament et
publiée par `php artisan filament:assets`), pas des classes Tailwind : le
thème d'un panel ne génère que les classes qu'il trouve dans ses propres
sources (`@source`), et une application n'a aucune raison d'y ajouter les
vues de ce package — des classes absentes du CSS compilé ne s'affichent
simplement pas (c'est ce qui était arrivé : ni rouge ni vert à l'écran).

> **Piège rencontré : le prompt doit garder les `/` du HTML intacts.** Sans
> `JSON_UNESCAPED_SLASHES`, `json_encode()` écrit chaque `</p>` en `<\/p>`
> dans le prompt ; l'IA imite ce style et renvoie de vrais antislashs, que
> le parseur HTML ne reconnaît plus comme des balises fermantes — un élément
> resté ouvert avale alors la suite du texte. `CorrectableField::sanitizeValues()`
> normalise en plus ces séquences dans les champs HTML d'une réponse.

Un futur `ChoiceRenderer`
(réponse = un choix parmi plusieurs options, affiché en boutons plutôt qu'en
diff) suivrait le même contrat sans toucher au reste du package.

### Sans clé API — échec propre plutôt qu'un plantage

La clé API se configure côté `prism-php/prism`, pas côté `filament-prism` —
pour le provider par défaut (`openai`), c'est **`OPENAI_API_KEY`** dans
`.env` (chaque provider a la sienne, voir `config/prism.php`, publié par
`prism-php/prism` : `ANTHROPIC_API_KEY`, `MISTRAL_API_KEY`...). Tant qu'elle
est absente, `CorrectionAction`/`GroupCorrectionAction` se désactivent
toutes seules (`CorrectionService::providerIsConfigured()`), avec un
tooltip qui le dit — inutile de cliquer sur un appel voué à échouer.

Si l'appel échoue quand même une fois lancé (clé refusée, réseau, quota...),
`CorrectionService` ne laisse jamais fuir l'exception brute du client HTTP :
elle est rattrapée et retraduite en `AiProviderException`, avec un message
sûr à montrer. Les deux actions l'attrapent à leur tour et affichent ce
message dans la modale au lieu de planter la page. Dans les deux cas,
l'exception d'origine reste signalée (`report()`), donc visible dans les
logs pour diagnostiquer.

## Utilisation : un modèle Eloquent (`Correctable`)

Le cas le plus courant : le texte corrigé vit dans les colonnes du modèle,
qui doit déclarer `implements CorrectionSubject` **en plus** de
`use Correctable;` — un trait ne peut pas déclarer d'interface à la place de
la classe qui le pose (même principe que `HasMedia`/`InteractsWithMedia` de
Spatie) :

```php
use CharlesStOlive\FilamentPrism\Concerns\Correctable;
use CharlesStOlive\FilamentPrism\Support\CorrectableField;
use CharlesStOlive\FilamentPrism\Support\CorrectionSubject;

class Article extends Model implements CorrectionSubject
{
    use Correctable;

    public static function correctableFields(): array
    {
        return [
            CorrectableField::make('title')->label('Titre'),
            CorrectableField::make('body')->label('Contenu')->html(),
        ];
    }
}
```

Puis, dans une page Filament (ex. l'édition d'un article, `$this->record`) :

```php
use CharlesStOlive\FilamentPrism\Filament\Actions\CorrectionAction;

// En popup :
CorrectionAction::make()->correctable(fn () => $this->record)

// En volet, si la page utilise HasSidePane (filament-ui) :
CorrectionSidePane::make('orthography', $this->record)
```

Aucun modèle de `desapp` n'utilise ce mode pour l'instant : les périodes du
carnet passent par `FieldsCorrectionSubject` (ci-dessous).

« Appliquer » écrit directement dans le modèle et le sauvegarde
(`CorrectionService::apply()`) — `autoApply` reste à `true` (son défaut).

## Utilisation : un texte hors modèle (`FieldsCorrectionSubject`)

Cas réel dans `desapp` : une période du carnet de voyage ne s'édite pas comme
un modèle Eloquent — son titre et son texte vivent dans
`EditVoyage::$dayData`, un tableau d'état Livewire (voir la docblock de
`EditVoyage::correctionAction()`). Le modèle Eloquent le plus proche et
stable est le voyage lui-même (`Orchestration`), pas le contenu affiché
(`OrchestratorContent`, une **copie dérivée** régénérée à chaque
sauvegarde — la corriger directement ne tiendrait pas).

```php
use CharlesStOlive\FilamentPrism\Filament\Actions\CorrectionAction;
use CharlesStOlive\FilamentPrism\Support\CorrectableField;
use CharlesStOlive\FilamentPrism\Support\FieldsCorrectionSubject;

CorrectionAction::make()
    ->visible(fn (): bool => filled($this->dayData['node_key'] ?? null))
    ->autoApply(false) // rien ne sait écrire tout seul dans $dayData
    ->trackable(fn (): Model => $this->record)
    ->correctable(fn (): FieldsCorrectionSubject => FieldsCorrectionSubject::make(
        model: $this->record,
        key: 'day:'.$this->dayData['node_key'], // distingue cette période des autres du même voyage
        fields: [
            CorrectableField::make('title')->label('Titre'),   // même libellé que le vrai champ du
            CorrectableField::make('body')->label('Contenu')->html(), // formulaire (voir meta.labels plus haut)
        ],
        get: fn (): array => [
            'title' => (string) ($this->dayData['title'] ?? ''),
            'body' => $this->bodyToHtml($this->dayData['body'] ?? null), // voir l'encart RichEditor ci-dessous
        ],
    ));
```

Avec `autoApply(false)`, « Appliquer » ne fait que marquer l'interaction
`applied` et envoyer les valeurs choisies via l'événement Livewire
`filament-prism:correction-applied` — à charge de la page qui a ouvert
l'action de les écrire (et de décider si/quand les persister) :

```php
#[On('filament-prism:correction-applied')]
public function onCorrectionApplied(int $interactionId, array $values): void
{
    if (array_key_exists('body', $values)) {
        $values['body'] = $this->bodyFromHtml((string) $values['body']); // voir l'encart RichEditor
    }

    $this->dayData = [...$this->dayData, ...$values]; // un brouillon, comme une frappe au clavier —
    // toujours à enregistrer via le bouton existant de la page, pas déjà en base.
}
```

> **Piège rencontré : un champ `RichEditor` de Filament n'est pas du HTML tant
> que le formulaire est ouvert.** Sa valeur Livewire live (`$dayData['body']`
> ici) est la structure TipTap native du champ (un document JSON) ; Filament
> ne la dehydrate en HTML qu'au moment de `$schema->getState()` (à la vraie
> soumission). L'envoyer telle quelle à l'IA la ferait « corriger » du JSON
> comme du texte — et un résultat mal formé réinjecté dans `$dayData['body']`
> casserait l'éditeur, faute de modèle Eloquent pour le retyper au passage.
> Solution : convertir dans les deux sens via l'éditeur TipTap **du champ
> lui-même** (pas une instance nue — il faut ses plugins, ex. des liens ou
> des références d'image personnalisés, pour ne rien perdre au passage) :
>
> ```php
> private function bodyToHtml(mixed $tiptapDocument): string
> {
>     $field = $this->getSchema('dayForm')->getComponent('body'); // le RichEditor, configuré
>     return $field->getTipTapEditor()->setContent($tiptapDocument ?? ['type' => 'doc', 'content' => []])->getHtml();
> }
>
> private function bodyFromHtml(string $html): array
> {
>     return $this->getSchema('dayForm')->getComponent('body')->getTipTapEditor()->setContent($html)->getDocument();
> }
> ```

## Utilisation : plusieurs sujets en un seul appel (`CorrectionSubjectGroup`)

Corriger plusieurs sujets qui partagent les mêmes champs — toutes les
périodes d'un voyage, par exemple — en **un seul appel IA** plutôt qu'un par
sujet : un schéma Prism répété (`{items: [{key, title, body}, ...]}`,
`CorrectableField::toGroupedObjectSchema()`), une seule `AiInteraction`.
`output.items` associe chaque correction reçue à son sujet par sa `key`,
jamais par position — l'IA ne garantit ni l'ordre ni la présence de chaque
élément demandé.

```php
use CharlesStOlive\FilamentPrism\Filament\Actions\GroupCorrectionAction;
use CharlesStOlive\FilamentPrism\Support\CorrectionSubjectGroup;

GroupCorrectionAction::make()
    ->visible(fn (): bool => $this->correctableDays() !== [])
    ->trackable(fn (): Model => $this->record)
    ->subjects(function (): CorrectionSubjectGroup {
        $subjects = [];

        foreach ($this->correctableDays() as $day) {
            $subjects[$day['slug']] = FieldsCorrectionSubject::make(
                model: $this->record,
                key: 'day:'.$day['slug'],
                fields: [CorrectableField::make('title'), CorrectableField::make('body')->html()],
                get: fn (): array => Arr::only($day, ['title', 'body']),
            );
        }

        return CorrectionSubjectGroup::make(model: $this->record, key: 'all-days', subjects: $subjects);
    });
```

`GroupCorrectionAction::subjects()` (et non `group()` — `Filament\Actions\Action`
porte déjà une méthode `group()` pour regrouper des actions dans un menu ;
une méthode du même nom avec une signature incompatible y déclare une erreur
fatale de classe, détectée seulement au premier rendu de la page, jamais au
chargement de la classe — un piège pour quiconque écrirait une nouvelle
action Filament avec un nom de méthode qui semble libre).

Comme `FieldsCorrectionSubject`, un groupe ne sait pas écrire son résultat
tout seul : `GroupCorrectionReview` envoie toujours les sujets choisis via
l'événement `filament-prism:group-correction-applied` (`values` keyed par la
même clé que `subjects`, ex. le slug) :

```php
#[On('filament-prism:group-correction-applied')]
public function onGroupCorrectionApplied(int $interactionId, array $values): void
{
    $days = array_values($this->data['days'] ?? []);

    foreach ($days as $index => $day) {
        if (isset($day['slug'], $values[$day['slug']])) {
            $days[$index] = [...$day, ...$values[$day['slug']]];
        }
    }

    $this->data['days'] = $days;
    $this->save(shouldRedirect: false, shouldSendSavedNotification: false); // pas de brouillon ouvert ici, contrairement
    $this->fillForm();                                                     // au cas seul : on enregistre tout de suite.
}
```

## Widgets de consommation

- `AiTokenUsageOverview` — tokens de l'utilisateur courant, aujourd'hui / ce
  mois (`StatsOverviewWidget`).
- `AiTokenUsageByTrackable` — historique des interactions d'un `trackable`
  donné (`AiTokenUsageByTrackable::make(['trackable' => $voyage])`), pour un
  futur « tokens de ce voyage » sur sa propre page.

## Roadmap (hors périmètre v1)

- `ChoiceRenderer` : une réponse IA à choix multiples, affichée en boutons
  plutôt qu'en diff (le contrat `AiResultRenderer` + la colonne `meta` sont
  déjà en place pour ça).
- D'autres tâches que `orthography` (traduction, reformulation...).
- Un vrai dialogue multi-tours (`thread_id`/`parent_interaction_id` déjà en
  place, inutilisés).
- Tests Pest côté package lui-même (aujourd'hui couvert côté application,
  `desapp/tests/Feature/VoyageCorrectionTest.php`, avec `Prism::fake()`).
