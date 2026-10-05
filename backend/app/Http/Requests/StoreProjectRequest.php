<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Services\Deployment\ImageReference;
use App\Services\Environment\EnvironmentKey;
use App\Services\Source\GitRefs;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProjectRequest extends FormRequest
{
    use ProjectSettingsRules;

    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60', 'regex:/^[\pL\pN][\pL\pN _.-]*$/u'],
            'source_type' => ['required', Rule::in([Project::SOURCE_GITHUB, Project::SOURCE_GIT, Project::SOURCE_IMAGE])],
            'repository' => ['required_if:source_type,github', 'nullable', 'string', 'max:201', 'regex:'.GitRefs::FULL_NAME_PATTERN],
            'repository_url' => ['required_if:source_type,git', 'nullable', 'string', 'max:500'],
            'branch' => ['required_unless:source_type,image', 'nullable', 'string', 'max:200'],
            'image' => ['required_if:source_type,image', 'nullable', 'string', 'max:255'],
            'domain' => ['nullable', 'string', 'max:253'],
            'database' => ['boolean'],
            'auto_deploy' => ['boolean'],
            'environment' => ['array', 'max:200'],
            'environment.*.key' => ['required', 'string', 'regex:'.EnvironmentKey::PATTERN, 'distinct'],
            'environment.*.value' => ['nullable', 'string', 'max:65535'],
            'environment.*.is_secret' => ['nullable', 'boolean'],
            'volumes' => ['array', 'max:10'],
            'volumes.*.name' => ['required', 'string', 'regex:/^[a-z][a-z0-9-]{0,30}$/', 'distinct'],
            'volumes.*.mount_path' => ['required', 'string', 'max:255', 'distinct'],
            ...$this->settingsRules(),
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $data = $this->all();
            if (($data['source_type'] ?? null) === Project::SOURCE_GIT && ! empty($data['repository_url'])) {
                if ($error = GitRefs::validateGitUrl($data['repository_url'], (bool) config('privatecloud.deploy.allow_insecure_git'))) {
                    $validator->errors()->add('repository_url', $error);
                }
            }
            if (! empty($data['branch']) && ! GitRefs::isValidBranch($data['branch'])) {
                $validator->errors()->add('branch', 'This is not a valid branch name.');
            }
            if (($data['source_type'] ?? null) === Project::SOURCE_IMAGE && ! empty($data['image']) && ! ImageReference::isValid($data['image'])) {
                $validator->errors()->add('image', 'Enter an image reference such as nginx:1.27 or ghcr.io/owner/app:latest.');
            }
            foreach ($data['volumes'] ?? [] as $i => $volume) {
                if ($error = self::mountPathError((string) ($volume['mount_path'] ?? ''))) {
                    $validator->errors()->add("volumes.$i.mount_path", $error);
                }
            }
            $this->validateSettings($validator);
        }];
    }
}
