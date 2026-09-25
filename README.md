# filament-prism

Registre de services IA (basés sur [`prism-php/prism`](https://prismphp.com))
pour Filament, avec persistance durable de chaque appel et suivi des tokens.

Chaque usage de l'IA est une **ressource IA** (`AiResource`) : son formulaire
d'entrée, son prompt, la forme de sa réponse, son traitement après l'appel et
sa réception, au même endroit. Le package livre la **correction orthographique
et grammaticale** (`orthography`) ; une application déclare les siennes
(extraction de factures, traduction...) sans toucher au cœur du package.

## Sommaire

- [Installation](#installation)
- [Concepts](#concepts)
  - [AiInteraction — la durabilité](#aiinteraction--la-durabilité)
  - [AiResource — une ressource IA](#airesource--une-ressource-ia)
  - [CorrectionSubject — quoi corriger](#correctionsubject--quoi-corriger)
  - [CorrectionService — appeler l'IA et appliquer le résultat](#correctionservice--appeler-lia-et-appliquer-le-résultat)
  - [AiResultRenderer — comment afficher le résultat](#airesultrenderer--comment-afficher-le-résultat)
  - [Sans clé API — échec propre plutôt qu'un plantage](#sans-clé-api--échec-propre-plutôt-quun-plantage)
- [Utilisation : un modèle Eloquent (`Correctable`)](#utilisation--un-modèle-eloquent-correctable)
- [Utilisation : un texte hors modèle (`FieldsCorrectionSubject`)](#utilisation--un-texte-hors-modèle-fieldscorrectionsubject)
- [Utilisation : plusieurs sujets en un seul appel (`CorrectionSubjectGroup`)](#utilisation--plusieurs-sujets-en-un-seul-appel-correctionsubjectgroup)
- [Utilisation : une ressource avec formulaire et réception (`AiResourceAction`)](#utilisation--une-ressource-avec-formulaire-et-réception-airesourceaction)
- [Demandes en arrière-plan et images (`queued()`, `generatesImages()`)](#demandes-en-arrière-plan-et-images-queued-generatesimages)
- [« Demandes IA » et « Consommation IA » (`FilamentPrismPlugin`)](#demandes-ia-et-consommation-ia-filamentprismplugin)
  - [Qui voit quoi (`AiAccess`)](#qui-voit-quoi-aiaccess)
  - [Afficher les demandes : modale, slide-over, volet](#afficher-les-demandes--modale-slide-over-volet)
  - [L'affichage d'une demande : piloté par la ressource](#laffichage-dune-demande--piloté-par-la-ressource)
- [Coûts en euros et facture réelle des fournisseurs](#coûts-en-euros-et-facture-réelle-des-fournisseurs)
- [Widgets de consommation](#widgets-de-consommation)
- [Mise à jour](#mise-à-jour)
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
| `status` | `queued` → `running` (une demande mise en file) → `pending` (résultat à vérifier) → `applied`/`discarded` ; ou `failed`. Rien n'est supprimé au clic « ignorer » : l'historique reste consultable. |
| `started_at`/`finished_at`, `error`, `cost` | La durée de l'appel, le message (sûr à montrer) d'un échec, et le coût, calculé à l'appel d'après `filament-prism.pricing` (`AiCost`) — figé, un changement de tarif ne réécrit pas l'historique. |
| `thread_id`/`parent_interaction_id` | Un fil : une demande et ses variantes (« Refaire » avec d'autres réglages, « Relancer » après un échec — `AiRunner::rerun()`). Prism sait aussi rejouer un historique de messages (`withMessages()`) : les mêmes colonnes serviront à un vrai dialogue multi-tours. |
| `meta` (json) | Ce dont un renderer a besoin sans connaître le `CorrectionSubject` d'origine (il ne survit pas à la requête qui a appelé l'IA) : `meta.labels` porte le libellé de chaque champ (`CorrectableField::labelsByName()`), utilisé par `TextDiffRenderer`/`GroupedTextDiffRenderer` — sans ça, `CorrectableField::make('body')->label('Contenu')` n'aurait aucun effet visible, la vue régénérant un libellé générique (`Str::headline($field)`) faute d'accès à la déclaration d'origine. Un futur `ChoiceRenderer` y noterait par exemple l'option choisie. |

### `AiResource` — une ressource IA

Une ressource = une classe qui étend `AiResource`, listée dans
`config('filament-prism.tasks')` et retrouvée par sa `key()`
(`AiTaskRegistry::resource()`). Chaque étape a une méthode, avec un défaut
raisonnable — une ressource ne redéfinit que ce qui la concerne :

| Étape | Méthode | Rôle |
|---|---|---|
| Avant | `inputSchema()` | Formulaire Filament à remplir avant l'appel (un fichier, un sélecteur...). `null` : aucun, le code appelant fournit tout (la correction). |
| Avant | `handleInput($data)` | Ce formulaire vers un ou plusieurs appels ; renvoie l'état initial de la réception. |
| Avant | `context($input)` | Ce que la ressource va chercher elle-même (une requête en base...) pour nourrir prompt et schéma. Jamais persisté. |
| Appel | `systemPrompt()` | Le rôle et les règles, fixes. |
| Appel | `prompt($input, $context)` | Le message, construit à partir des données. Défaut : l'entrée en JSON. |
| Appel | `attachments()` | Documents/images joints (`Document::fromLocalPath()`...). |
| Appel | `responseSchema()` | La forme du retour : un schéma Prism (JSON structuré), ou `null` pour du texte libre (`output = ['text' => ...]`). |
| Appel | `tools()` / `maxSteps()` | Les fonctions que l'IA peut appeler pendant l'appel. |
| Appel | `configureRequest($request)` | Le reste : température, options du provider... |
| Après | `resolve($output, $input, $context)` | Normalise et complète la réponse en PHP (dates, rapprochement en base, avertissements). C'est ce résultat qui est persisté dans `output` ; la réponse brute reste dans `meta.raw`. |
| Après | `receptionSchema()` | Le formulaire de vérification du résultat. |
| Après | `apply($data)` | La soumission de cette vérification. |

`provider()`/`model()` reprennent la config par défaut ; une ressource peut
les redéfinir. `AiRunner::run()` porte le cycle commun : réutiliser une
réponse `pending` déjà payée pour la même entrée, sinon enregistrer la
demande, appeler l'IA, `resolve()`, persister la réponse — ou l'échec
(`failed`, puis l'exception relancée : les stats comptent les échecs).

`OrthographyTask` est une ressource sans formulaire d'entrée ni réception de
formulaire : son texte vient d'un `CorrectionSubject`, sa réception est la
revue de correction (`CorrectionReview`, voir `AiResultRenderer`).

```php
// config/filament-prism.php
'tasks' => [
    \CharlesStOlive\FilamentPrism\Tasks\OrthographyTask::class,
    \App\Ai\SupplierInvoiceExtraction::class, // exemple
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

Ce que la ressource de correction ne peut pas savoir seule — quel texte
envoyer, à quel modèle rattacher la réponse, comment l'y écrire. L'appel
lui-même passe par `AiRunner`, comme pour toute ressource.

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

## Utilisation : une ressource avec formulaire et réception (`AiResourceAction`)

Une modale en deux étapes : le formulaire d'entrée (`inputSchema()`, rangé sous
`input`) ; à sa validation, `handleInput()` lance les appels et prépare la
réception (`receptionSchema()`, rangée sous `reception`), que la soumission
passe à `apply()`. Sans formulaire d'entrée, l'appel part à l'ouverture et
seule la réception s'affiche. Même garde-fou que la correction : bouton
désactivé sans clé API, message propre si l'appel échoue.

```php
use CharlesStOlive\FilamentPrism\Filament\Actions\AiResourceAction;

AiResourceAction::make('createFromFile')
    ->aiResource('supplier-invoice-extraction')
    ->label('Créer à partir de fichiers')
```

`aiResource()` et non `resource()`, pour la même raison que
`GroupCorrectionAction::subjects()` (voir plus haut).

Exemple complet côté application : `filbreeze/app/Ai/SupplierInvoiceExtraction.php`
— les fichiers sont scannés (texte du PDF, sinon OCR), l'IA choisit le
fournisseur dans la liste des fournisseurs connus (`context()` +
`EnumSchema`), `resolve()` recoupe par SIRET/TVA/nom et signale doublons et
totaux incohérents, chaque fichier devient une facture brouillon, et `apply()`
valide les brouillons vérifiés. L'interaction, sans modèle au moment de
l'appel, est identifiée par l'empreinte du fichier (`subjectKey`) puis
rattachée à la facture créée.

## Demandes en arrière-plan et images (`queued()`, `generatesImages()`)

Une image générée prend une minute, parfois plus : trop pour tenir une requête
ouverte. Une ressource qui déclare `queued(): true` est enregistrée tout de
suite (`queued`), et un job (`RunAiInteraction`) fait l'appel ; son auteur
est prévenu dans les notifications Filament en base quand elle est prête ou a
échoué (`AiInteractionNotifier`). Son entrée doit tenir en JSON (des
identifiants, pas des fichiers) : c'est tout ce que reçoit le job, la
ressource retrouve le reste dans `context()`.

```php
app(AiRunner::class)->queue($resource, ['media_ids' => [12]], trackable: $voyage);
app(AiRunner::class)->rerun($interaction, ['quality' => 'high']); // même fil, autres réglages
```

Une seule tentative (`tries = 1`) : relancer tout seul un appel, c'est risquer
de le payer deux fois. Le job ne fait rien d'une demande qui n'est plus
`queued`, et la marque `failed` si le worker l'interrompt.

> **Piège : `retry_after` de la queue.** Il doit dépasser le temps laissé à une
> demande (`filament-prism.queue.timeout`, 300 s), et le `--timeout` du worker
> aussi — sinon une demande encore en cours est reprise par un autre worker.
> Pour la connexion `database` : `DB_QUEUE_RETRY_AFTER=400`,
> `php artisan queue:work --tries=1 --timeout=330`.

Une ressource qui déclare `generatesImages(): true` appelle `Prism::image()` :
ses `attachments()` sont les photos à retravailler (OpenAI passe alors par
`images/edits`, jusqu'à 16 images), `configureImageRequest()` règle taille et
qualité (`withProviderOptions()`), et les images reçues sont rangées sur le
disque de la config (`filament-prism.images`) — `output.images` en garde le
chemin, `AiInteraction::images()` l'adresse. Le délai d'attente est allongé
(`timeout()`, 240 s : celui de prism, 30 s, est trop court pour une image) ;
un modèle d'image n'ayant pas de prompt système, `systemPrompt()` est placé en
tête du prompt.

Ce que « Demandes IA » montre et permet d'une demande, la ressource le dit :
`resultSchema()`/`inputDisplaySchema()` (voir plus bas), `sourcePreviews()`
(les images de départ), `describeInput()` (les réglages, lisibles),
`applyResultLabel()`/`applyResult()` (accepter un résultat — ex.
l'ajouter à une bibliothèque ; sans libellé, un résultat se consulte, s'ignore
ou se refait seulement), `resultUrl()` (où mène la notification).

`AiResourceAction` sait aussi lancer une ressource mise en file : sa modale ne
montre que le formulaire d'entrée et se ferme à l'envoi
(`->trackable($voyage)` pour le périmètre).

## « Demandes IA » et « Consommation IA » (`FilamentPrismPlugin`)

```php
->plugins([
    FilamentPrismPlugin::make()
        ->navigationGroup('IA')
        ->seeAllRequestsUsing(fn (User $user): bool => $user->can('…')), // voir plus bas
])
->databaseNotifications() // la notification de fin d'une demande mise en file
```

- **Demandes IA** (`AiInteractionResource`) : les demandes (ressource, statut,
  dates) ; une demande s'ouvre sur son fil — ses variantes, chacune avec son
  résultat et ses boutons (« Accepter », « Ignorer », « Refaire », « Relancer »).
- **Consommation IA** (`AiUsageStats`) : sa propre consommation (tokens du
  jour, du mois, coût du mois), ce que les fournisseurs ont facturé, puis, par
  ressource, par utilisateur et par modèle sur une période : demandes, échecs,
  taux d'acceptation (acceptées / vérifiées), tokens, coût estimé et part de
  la facture en euros, durée moyenne ; les demandes dans le temps (voir
  « Coûts en euros » plus bas).

### Qui voit quoi (`AiAccess`)

Chacun ne voit que **ses** demandes et **sa** consommation — listes, fil d'une
demande (la page d'une demande d'un autre répond 404), stats. Qui peut tout
voir voit celles de tout le monde, **avec leur auteur** (sur chaque carte, en
colonne et en filtre de « Demandes IA »), et la consommation **par
utilisateur** (`AiUsageByUser`).

« Tout voir » se décide dans le panel (`seeAllRequestsUsing()`), sinon par
l'ability Gate `filament-prism.see-all-requests` (refusée tant que
l'application ne la définit pas). `AiInteractionResource` déclare aussi la
permission `aiinteraction.viewallusers` au format de
charlesstolive/filament-permission-manager (`$specificPermissions`, lu par
`permissions:sync`) sans dépendre de ce package ; une application qui s'en
sert la vérifie ainsi :

```php
->seeAllRequestsUsing(function (User $user): bool {
    try {
        return PermissionService::userCan($user, AiInteractionResource::SEE_ALL_PERMISSION);
    } catch (PermissionDoesNotExist) {
        return false; // pas encore créée par permissions:sync
    }
})
```

> **Piège : `PermissionService::userCan()` lève une exception pour une
> permission absente de la base**, au lieu de répondre non. Sans le `try`,
> toute page qui montre une demande IA plante tant que `permissions:sync` n'a
> pas tourné.

### Afficher les demandes : modale, slide-over, volet

La même liste (`AiInteractionList`, une carte `AiInteractionCard` par demande,
les plus récentes d'abord), trois façons de l'ouvrir :

```php
AiInteractionsAction::make()                                        // modale : toutes mes demandes
AiInteractionsAction::make()->trackable($this->record)->slideOver() // slide-over : celles de ce voyage
AiInteractionsSidePane::make($this->record, tasks: ['photo-sketch']) // volet latéral de filament-ui (HasSidePane)
```

`trackable` restreint à ce qui a été demandé pour un modèle, `tasks` à
certaines ressources ; sans l'un ni l'autre : toutes ses demandes. Le bouton
de `AiInteractionsAction` porte le nombre de demandes en cours. Une carte se
redessine seule toutes les 5 s tant que sa demande tourne ; la liste, quand une
demande part ou aboutit sur la page (`AiInteractionList::CHANGED_EVENT`).

### L'affichage d'une demande : piloté par la ressource

Réglages et résultat sont des **composants Filament** — des entrées
d'infolist, le plus souvent des `TextEntry` —, que la ressource déclare ; le
schéma porte la demande comme `record` :

```php
public function resultSchema(AiInteraction $interaction): ?array
{
    return [
        TextEntry::make('output.summary')->label('Résumé')->columnSpanFull(),
        TextEntry::make('output.language')->label('Langue')->badge(),
    ];
}

public function inputDisplaySchema(AiInteraction $interaction): ?array { /* les réglages */ }
```

Sans rien de déclaré (`null`), `AiResultSchema` affiche une **grille
Filament** (`Grid`) : une `TextEntry` par valeur de la réponse, libellée par
`meta.labels` s'il y en a (oui/non, liste à puces, HTML nettoyé, texte long sur
toute la largeur, JSON à défaut) ; les images de départ (`sourcePreviews()`) et
produites (`output.images`) en `ImageEntry`, qui s'ouvrent en grand ; les
réglages, ceux de `describeInput()` en grille. La correction orthographique
déclare le sien : le diff « Avant / Après » de sa revue.

## Coûts en euros et facture réelle des fournisseurs

Les tokens ne parlent pas toujours : chaque demande porte aussi un **coût
estimé** (tokens × prix du catalogue), dans la devise du fournisseur (`cost`,
`currency`) et **en euros** (`cost_eur`, au taux de référence BCE du jour), et
le type de modèle appelé (`kind` : `text`, `structured`, `image`).

Une requête coûte souvent une fraction de centime : les coûts sont stockés
avec **8 décimales**, et affichés par `AiMoney::format()` avec trois chiffres
significatifs sous 1 (0,000297 € ; 0,0938 €), deux décimales au-delà
(12,40 €) — jamais « 0,00 € » pour une petite correction.

Le catalogue décrit fournisseurs et modèles (`filament-prism.providers`) :
libellé, devise, source de facturation, et par modèle son type et ses prix
pour un million de tokens (`input`, `output`, `input_image`). Un modèle absent
a un coût **inconnu**, pas nul — « Par modèle » le signale. L'ancienne table
`filament-prism.pricing` (par modèle seul) reste lue en repli.

Ce que le fournisseur a **réellement facturé** se relève dans son API de
facturation (`BillingSource`), jour par jour et ligne par ligne, dans
`ai_billed_costs` :

| Fournisseur | API | Clé | Restreindre à l'application |
|---|---|---|---|
| OpenAI (`OpenAiBilling`) | `GET /v1/organization/costs` | `OPENAI_ADMIN_KEY` — clé **admin** d'organisation, pas la clé de projet (`sk-proj-…`) des appels | `OPENAI_PROJECT_ID` |
| Anthropic (`AnthropicBilling`) | `GET /v1/organizations/cost_report` (montants en cents) | `ANTHROPIC_ADMIN_KEY` (`sk-ant-admin…`) | `ANTHROPIC_WORKSPACE_ID` |

```bash
php artisan filament-prism:sync-billing --days=7   # taux BCE + factures + coûts manquants
```

```php
// routes/console.php de l'application
Schedule::command('filament-prism:sync-billing')->dailyAt('06:00');
```

Le relevé est idempotent (un jour relevé deux fois est remplacé), convertit
chaque ligne au taux BCE de son jour (dernier publié, un week-end), et complète
les demandes sans coût (modèle sans prix à l'époque de l'appel : calculé
d'après leurs tokens) ou sans euros. « Relever la facture », sur la page de
stats, fait de même tout de suite.

La facture ne connaît ni les ressources ni les utilisateurs : la **part de la
facture** de chacun est son estimation **recalée** — multipliée, fournisseur
par fournisseur, par le rapport facturé / estimé de la période. Sans projet
précisé, la facture couvre toute l'organisation (autres applications
comprises) : le rapport le montre.

## Widgets de consommation

- `AiTokenUsageOverview` — la consommation de l'utilisateur courant : tokens
  aujourd'hui / ce mois, coût du mois (`StatsOverviewWidget`).
- `AiBillingByProvider` (qui peut tout voir seulement), `AiUsageByResource`,
  `AiUsageByUser` (idem), `AiUsageByModel`, `AiUsageChart` — ceux de
  « Consommation IA », qui lisent ses filtres.
- `AiTokenUsageByTrackable` — historique des interactions d'un `trackable`
  donné (`AiTokenUsageByTrackable::make(['trackable' => $voyage])`), pour un
  futur « tokens de ce voyage » sur sa propre page.

## Mise à jour

Une application qui utilise déjà le package republie ses migrations et migre :
`add_run_tracking_to_ai_interactions_table` ajoute `started_at`,
`finished_at`, `error` et `cost` ; `add_billing_to_ai_interactions_table`
ajoute `kind`, `currency`, `cost_eur` et les tables `ai_billed_costs`,
`ai_exchange_rates` ; `increase_ai_cost_precision` passe les coûts à 8
décimales. `AiRunner` écrit ces colonnes à chaque appel, **même
synchrone** : sans ces migrations, tout appel échoue. La config passe de
`pricing` à `providers` (l'ancienne clé reste lue en repli) : republier ou
reporter le catalogue dans `config/filament-prism.php`.

```bash
sail artisan vendor:publish --tag=filament-prism-migrations
sail artisan migrate
```

## Roadmap (hors périmètre v1)

- `ChoiceRenderer` : une réponse IA à choix multiples, affichée en boutons
  plutôt qu'en diff (le contrat `AiResultRenderer` + la colonne `meta` sont
  déjà en place pour ça).
- Traduction, reformulation... en ressources IA.
- Un vrai dialogue multi-tours (`thread_id`/`parent_interaction_id` servent
  déjà aux variantes d'une demande).
- Nettoyer les images des demandes ignorées (elles restent sur le disque, avec
  l'historique).
- Tests Pest côté package lui-même (aujourd'hui couvert côté application,
  `desapp/tests/Feature/VoyageCorrectionTest.php`, avec `Prism::fake()`).
