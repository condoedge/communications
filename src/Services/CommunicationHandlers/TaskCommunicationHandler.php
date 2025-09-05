<?php

namespace Condoedge\Communications\Services\CommunicationHandlers;

use Condoedge\Communications\EventsHandling\Contracts\TaskCommunicableEvent;
use Condoedge\Communications\Facades\ContextEnhancer;
use Condoedge\Communications\Services\CommunicationHandlers\AbstractCommunicationHandler;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\TaskCommunicable;
use Kompo\Tasks\Models\Enums\TaskStatusEnum;
use Kompo\Tasks\Models\Enums\TaskVisibilityEnum;
use Kompo\Tasks\Models\Task;
use Kompo\Tasks\Models\TaskDetail;
use Kompo\Tasks\Models\TaskAssignation;

class TaskCommunicationHandler extends AbstractCommunicationHandler
{
    public function communicableInterface()
    {
        return TaskCommunicable::class;
    }

    public function communicableEventInterface()
    {
        return TaskCommunicableEvent::class;
    }

    // NOTIFICATION
    /**
     * @param TaskCommunicable[] $communicables
     * @param mixed $params
     * @return void
     */
    public function notifyCommunicables(array $communicables, $params = [])
    {
        /**
         * @var TaskCommunicableEvent|null $event
         */
        $event = $params['trigger_instance'] ?? null;

        if ($event && $assignables = $event->sendToAnotherAssignables()) {
            $params = ContextEnhancer::setContext($params)->getEnhancedContext();
            $task = $this->createTaskWithDetail($params);

            TaskAssignation::createForMany($task->id, $assignables);

            // Break the flow here, we already created the task and assigned it to the assignables
            return;
        }
        
        collect($communicables)->map(function($communicable) use ($params) {
            $params = ContextEnhancer::setCommunicable($communicable)->setContext($params)->getEnhancedContext();
            $task = $this->createTaskWithDetail($params, $communicable->getId());
        });
    }

    protected function getTeamId($params)
    {
        return $params['task_team_id'] ?? $params['team_id'] ?? $params['team']->id ?? $params['teams_ids'][0] ?? null;
    }

    /**
     * Create task with detail
     */
    protected function createTaskWithDetail(array $params, ?int $assignedTo = null): Task
    {
        $title = $this->communication->getParsedTitle($params);
        $content = $this->communication->getParsedContent($params);

        $task = new Task();
        $task->title = $title;
        $task->status = TaskStatusEnum::OPEN;
        $task->visibility = TaskVisibilityEnum::ALL;
        $task->assigned_to = $assignedTo;
        $task->team_id = $this->getTeamId($params);
        $task->save();

        $taskDetail = new TaskDetail();
        $taskDetail->task_id = $task->id;
        $taskDetail->setUserId(systemUserId()); // System id
        $taskDetail->details = $content;
        $taskDetail->save();

        return $task;
    }
}
