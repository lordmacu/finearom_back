<?php

namespace App\Http\Controllers;

use App\Http\Requests\Project\ProjectAssignEngineerRequest;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectEngineerService;
use App\Support\ProjectEngineerPermission;
use Illuminate\Http\JsonResponse;

class ProjectEngineerController extends Controller
{
    public function __construct(
        private readonly ProjectEngineerService $service,
    ) {
        $this->middleware('can:' . ProjectEngineerPermission::ASSIGN)->only(['update']);
    }

    public function update(ProjectAssignEngineerRequest $request, Project $project): JsonResponse
    {
        $engineer = User::findOrFail($request->validated('desarrollador_id'));
        $project  = $this->service->assign($project, $engineer, $request->user()->name);

        return response()->json([
            'success' => true,
            'data'    => $project->fresh()->load('desarrollador:id,name,email'),
            'message' => "Ingeniero asignado: {$engineer->name}",
        ]);
    }
}
