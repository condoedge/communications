<?php

namespace Condoedge\Communications\Services\CommunicationHandlers;

use Condoedge\Communications\Facades\ContextEnhancer;
use Condoedge\Communications\Services\CommunicationHandlers\AbstractCommunicationHandler;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\TaskCommunicable;
use Kompo\Tasks\Models\Enums\TaskStatusEnum;
use Kompo\Tasks\Models\Enums\TaskVisibilityEnum;
use Kompo\Tasks\Models\Task;
use Kompo\Tasks\Models\TaskDetail;

class TaskCommunicationHandler extends AbstractCommunicationHandler
{
    public function communicableInterface()
    {
        return TaskCommunicable::class;
    }

    // NOTIFICATION
    /**
     * @param TaskCommunicable[] $communicables
     * @param mixed $params
     * @return void
     */
    public function notifyCommunicables(array $communicables, $params = [])
    {
        $communicables = collect($communicables)->map(function($communicable) use ($params) {
            $params = ContextEnhancer::setCommunicable($communicable)->setContext($params)->getEnhancedContext();

            $title = $this->communication->getParsedTitle($params);
            $content = $this->communication->getParsedContent($params);

            $task = new Task();
            $task->title = $title;
            $task->status = TaskStatusEnum::OPEN;
            $task->visibility = TaskVisibilityEnum::ALL;
            $task->assigned_to = $communicable->getId();
            $task->team_id = $params['team_id'] ?? $params['team']->id ?? null;
            $task->save();

            $taskDetail = new TaskDetail();
            $taskDetail->task_id = $task->id;
            $taskDetail->setUserId($communicable->getId());
            $taskDetail->details = $content;
            $taskDetail->save();
        });
    }
}
