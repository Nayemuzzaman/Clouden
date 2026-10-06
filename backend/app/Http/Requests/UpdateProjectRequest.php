<?php

namespace App\Http\Requests;

use App\Services\Deployment\ImageReference;
use App\Services\Source\GitRefs;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateProjectRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:60', 'regex:/^[\pL\pN][\pL\pN _.-]*$/u'],
            'branch' => ['sometimes', 'string', 'max:200'],
            'repository' => ['sometimes', 'string', 'max:201', 'regex:'.GitRefs::FULL_NAME_PATTERN],
            'repository_url' => ['sometimes', 'string', 'max:500'],
            'image' => ['sometimes', 'string', 'max:255'],
            ...$this->settingsRules(),
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->has('branch') && ! GitRefs::isValidBranch((string) $this->input('branch'))) {
                $validator->errors()->add('branch', 'This is not a valid branch name.');
            }
            if ($this->has('repository_url') && ($error = GitRefs::validateGitUrl((string) $this->input('repository_url'), (bool) config('privatecloud.deploy.allow_insecure_git')))) {
                $validator->errors()->add('repository_url', $error);
            }
            if ($this->has('image') && ! ImageReference::isValid((string) $this->input('image'))) {
                $validator->errors()->add('image', 'Enter a valid image reference.');
            }
            $this->validateSettings($validator);
        }];
    }
}
