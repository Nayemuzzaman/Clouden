<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Setting;
use Illuminate\Support\Str;

/**
 * Identity of this PrivateCloud installation.
 *
 * Project ids start at 1 in every installation, so labels such as
 * "privatecloud.project=1" alone cannot tell this installation's containers and
 * volumes from those left on the same Docker host by an earlier installation
 * (e.g. a reinstall with a fresh platform database). Every Docker object is
 * therefore also labelled with a random installation id, stored in the
 * platform database (and restored with it after a disaster recovery).
 * Objects carrying another installation's id are never removed or mounted.
 */
class Instance
{
    public const LABEL = 'privatecloud.instance';

    private const SETTING = 'instance.id';

    private ?string $id = null;

    public function id(): string
    {
        if ($this->id !== null) {
            return $this->id;
        }
        // firstOrCreate tolerates the race between the API and the workers on first start.
        $setting = Setting::query()->firstOrCreate(['key' => self::SETTING], ['value' => (string) Str::uuid()]);

        return $this->id = (string) $setting->value;
    }

    /** @return array<string, string> */
    public function labels(Project $project): array
    {
        return [
            'privatecloud.managed' => 'true',
            'privatecloud.project' => (string) $project->id,
            self::LABEL => $this->id(),
        ];
    }

    /**
     * Whether Docker object labels belong to this installation. Objects created
     * before installation ids existed have no such label and are accepted.
     *
     * @param  array<string, mixed>|null  $labels
     */
    public function owns(?array $labels): bool
    {
        $label = $labels[self::LABEL] ?? null;

        return $label === null || $label === $this->id();
    }

    /** @param array<string, mixed>|null $labels */
    public function ownsProjectObject(?array $labels, Project $project): bool
    {
        return $this->owns($labels) && ($labels['privatecloud.project'] ?? null) === (string) $project->id;
    }
}
