# communications

PENDING

You should create an event that will trigger the communication

```php
class OpenedReinscriptions implements CommunicableEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    protected $event;
    protected $teamsIds;

    public function __construct($event)
    {
        $this->event = $event;
    }

    function getParams(): array
    {
        return [
            'event' => $this->event,
        ];
    }

    function getCommunicables(): array|Collection
    {
        $persons = Person::whereHas('personTeams', fn($q) => $q->whereIn('team_id', $this->event->team->teams()->pluck('id'))->whereHas(
            'teamRole', fn($q) => $q->where('role', InscriptionTypeEnum::PARENT)
        ))->whereDoesntHave('personEvents', fn($q) => $q->whereHas('person.registeredBy', fn($q) => $q->whereColumn('id', 'persons.id')));

        return $persons->get();
    }

    static function getName(): string
    {
        return 'opened reinscriptions';
    }
}
```

Register the trigger in config/kompo-communications.php

```php
    return [
        'triggers' => [
            OpenedReinscriptions::class,
        ],
    ];
```

To be able to have variables in the ckeditor, you should add this kind of code in the AppServiceProvider file

```php
    Variables::setVariables([
        'events' => [
            // ID, name, classes, automatic handling (access to the object and attribute)
            ['event.name_ev', 'Event name', 'bg-level1', true]
        ],
        'teams' => [
            ['team_name', 'Team name', 'bg-level1', false]
        ]
    ]);

    // We could also use the automatic handling of the variables ({model.attribute}) for simple cases
    ContentReplacer::setHandlers([
        'team_name' => function ($team) {
            return $team->name;
        }
    ]);

    // We could also implement the method `enhanceContext` in the model to do this (I think it's better)
    ContextEnhancer::setEnhancers([
        'event' => function ($event) {
            return [
                'team' => $event->team,
                'teams_ids' => [$event->team_id],
            ];
        }
    ]);
```