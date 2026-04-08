<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Prompt;
use App\Models\PromptVersion;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    // GET /api/admin/prompts
    public function listPrompts(): JsonResponse
    {
        $prompts = Prompt::orderBy('key')->get();
        return response()->json($prompts);
    }

    // GET /api/admin/prompts/{key}
    public function getPrompt(string $key): JsonResponse
    {
        $prompt = Prompt::where('key', $key)->firstOrFail();
        return response()->json($prompt);
    }

    // PUT /api/admin/prompts/{key}
    public function updatePrompt(Request $request, string $key): JsonResponse
    {
        $request->validate([
            'template'    => 'required|string|min:10',
            'change_note' => 'nullable|string|max:255',
        ]);

        $prompt = Prompt::where('key', $key)->firstOrFail();

        // Save current version to history before overwriting
        PromptVersion::create([
            'prompt_key'  => $prompt->key,
            'template'    => $prompt->template,
            'version'     => $prompt->version,
            'saved_by'    => auth()->id(),
            'change_note' => $request->change_note,
            'saved_at'    => now(),
        ]);

        $prompt->update([
            'template' => $request->template,
            'version'  => $prompt->version + 1,
        ]);

        return response()->json($prompt->fresh());
    }

    // GET /api/admin/prompts/{key}/versions
    public function promptVersions(string $key): JsonResponse
    {
        $versions = PromptVersion::where('prompt_key', $key)
            ->with('savedBy:id,name,email')
            ->orderByDesc('version')
            ->get();

        return response()->json($versions);
    }

    // POST /api/admin/prompts/{key}/restore/{version}
    public function restoreVersion(Request $request, string $key, int $version): JsonResponse
    {
        $prompt = Prompt::where('key', $key)->firstOrFail();
        $oldVersion = PromptVersion::where('prompt_key', $key)
            ->where('version', $version)
            ->firstOrFail();

        // Save current as a version before restoring
        PromptVersion::create([
            'prompt_key'  => $prompt->key,
            'template'    => $prompt->template,
            'version'     => $prompt->version,
            'saved_by'    => auth()->id(),
            'change_note' => "Auto-saved before restoring v{$version}",
            'saved_at'    => now(),
        ]);

        $prompt->update([
            'template' => $oldVersion->template,
            'version'  => $prompt->version + 1,
        ]);

        return response()->json($prompt->fresh());
    }

    // GET /api/admin/users
    public function listUsers(): JsonResponse
    {
        $users = User::select('id', 'name', 'email', 'is_admin', 'is_paid', 'login_count', 'created_at')
            ->orderBy('id')
            ->get();
        return response()->json($users);
    }
}
