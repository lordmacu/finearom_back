<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectGoogleTaskConfig;
use App\Services\GoogleTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectGoogleTaskConfigController extends Controller
{
    public function __construct(
        private readonly GoogleTaskService $googleTaskService
    ) {
        $this->middleware('auth:sanctum');
        $this->middleware('can:project list')->only(['show']);
        $this->middleware('can:project edit')->only(['update']);
    }

    /**
     * GET /api/projects/{project}/google-task-config
     */
    public function show(Project $project): JsonResponse
    {
        $configs = $project->googleTaskConfigs()->get()->keyBy('trigger');

        $data = [
            'on_create'        => ['user_ids' => $configs->get('on_create')?->user_ids ?? []],
            'on_status_change' => ['user_ids' => $configs->get('on_status_change')?->user_ids ?? []],
            'near_deadline'    => ['user_ids' => $configs->get('near_deadline')?->user_ids ?? []],
        ];

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * PUT /api/projects/{project}/google-task-config
     */
    public function update(Request $request, Project $project): JsonResponse
    {
        $triggers = ['on_create', 'on_status_change', 'near_deadline'];

        foreach ($triggers as $trigger) {
            $userIds = $request->input("{$trigger}.user_ids", []);

            if (empty($userIds)) {
                ProjectGoogleTaskConfig::where('project_id', $project->id)
                    ->where('trigger', $trigger)
                    ->delete();
            } else {
                ProjectGoogleTaskConfig::updateOrCreate(
                    ['project_id' => $project->id, 'trigger' => $trigger],
                    ['user_ids' => array_values(array_unique(array_map('intval', $userIds)))]
                );
            }
        }

        return response()->json(['success' => true, 'message' => 'Configuración guardada']);
    }
}
