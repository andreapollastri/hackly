<?php

namespace App\Domain\Compliance;

use App\Models\Asset;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * One line in the compliance filter: a target, a repository, or both when they are linked.
 */
class ComplianceSubject
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?string $assetId = null,
        public readonly ?string $repositoryId = null,
    ) {}

    public static function all(): self
    {
        return new self('', 'All');
    }

    public function isAll(): bool
    {
        return $this->assetId === null && $this->repositoryId === null;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $assets = Asset::query()->orderBy('value')->get(['id', 'value']);
        $repositories = Repository::query()->orderBy('full_name')->get(['id', 'full_name']);
        $links = DB::table('asset_repository')->get(['asset_id', 'repository_id']);
        $linkedAssets = $links->pluck('asset_id')->unique();
        $linkedRepositories = $links->pluck('repository_id')->unique();
        $options = [];

        foreach ($assets as $asset) {
            if ($linkedAssets->contains($asset->id)) {
                continue;
            }

            $options['target:'.$asset->id] = $asset->value;
        }

        foreach ($repositories as $repository) {
            if ($linkedRepositories->contains($repository->id)) {
                continue;
            }

            $options['repository:'.$repository->id] = $repository->full_name;
        }

        foreach ($links as $link) {
            $asset = $assets->firstWhere('id', $link->asset_id);
            $repository = $repositories->firstWhere('id', $link->repository_id);

            if ($asset === null || $repository === null) {
                continue;
            }

            $options['pair:'.$asset->id.':'.$repository->id] = $asset->value.' + '.$repository->full_name;
        }

        asort($options);

        return $options;
    }

    public static function fromKey(?string $key): self
    {
        $key = trim((string) $key);

        if ($key === '' || $key === 'all') {
            return self::all();
        }

        if (preg_match('/^target:([0-9a-fA-F-]{36})$/', $key, $match) === 1 && Str::isUuid($match[1])) {
            $asset = Asset::query()->find($match[1]);

            if (! $asset instanceof Asset) {
                throw new ModelNotFoundException('Unknown target.');
            }

            return new self($key, $asset->value, $asset->id, null);
        }

        if (preg_match('/^repository:([0-9a-fA-F-]{36})$/', $key, $match) === 1 && Str::isUuid($match[1])) {
            $repository = Repository::query()->find($match[1]);

            if (! $repository instanceof Repository) {
                throw new ModelNotFoundException('Unknown repository.');
            }

            return new self($key, $repository->full_name, null, $repository->id);
        }

        if (preg_match('/^pair:([0-9a-fA-F-]{36}):([0-9a-fA-F-]{36})$/', $key, $match) === 1
            && Str::isUuid($match[1])
            && Str::isUuid($match[2])) {
            $asset = Asset::query()->find($match[1]);
            $repository = Repository::query()->find($match[2]);
            $linked = $asset && $repository && DB::table('asset_repository')
                ->where('asset_id', $asset->id)
                ->where('repository_id', $repository->id)
                ->exists();

            if (! $linked) {
                throw new ModelNotFoundException('That target is not linked to that repository.');
            }

            return new self($key, $asset->value.' + '.$repository->full_name, $asset->id, $repository->id);
        }

        throw new InvalidArgumentException('Unknown compliance subject.');
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function constrainFindings(Builder $query): Builder
    {
        if ($this->isAll()) {
            return $query;
        }

        return $query->where(function (Builder $inner): void {
            if ($this->assetId !== null) {
                $inner->orWhere('asset_id', $this->assetId);
            }

            if ($this->repositoryId !== null) {
                $inner->orWhere('repository_id', $this->repositoryId);
            }
        });
    }

    /**
     * @param  Builder<Asset>  $query
     * @return Builder<Asset>
     */
    public function constrainAssets(Builder $query): Builder
    {
        if ($this->isAll()) {
            return $query;
        }

        return $this->assetId === null ? $query->whereRaw('1 = 0') : $query->whereKey($this->assetId);
    }

    /**
     * @param  Builder<Repository>  $query
     * @return Builder<Repository>
     */
    public function constrainRepositories(Builder $query): Builder
    {
        if ($this->isAll()) {
            return $query;
        }

        return $this->repositoryId === null ? $query->whereRaw('1 = 0') : $query->whereKey($this->repositoryId);
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function constrainScans(Builder $query): Builder
    {
        if ($this->isAll()) {
            return $query;
        }

        return $this->assetId === null ? $query->whereRaw('1 = 0') : $query->where('asset_id', $this->assetId);
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function constrainRepoScans(Builder $query): Builder
    {
        if ($this->isAll()) {
            return $query;
        }

        return $this->repositoryId === null ? $query->whereRaw('1 = 0') : $query->where('repository_id', $this->repositoryId);
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function constrainScanTasks(Builder $query): Builder
    {
        if ($this->isAll()) {
            return $query;
        }

        if ($this->assetId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('scan', fn (Builder $scan) => $scan->where('asset_id', $this->assetId));
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function constrainRepoScanTasks(Builder $query): Builder
    {
        if ($this->isAll()) {
            return $query;
        }

        if ($this->repositoryId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('scan', fn (Builder $scan) => $scan->where('repository_id', $this->repositoryId));
    }

    /**
     * @return array{key: string, label: string}|null
     */
    public function payload(): ?array
    {
        if ($this->isAll()) {
            return null;
        }

        return [
            'key' => $this->key,
            'label' => $this->label,
        ];
    }
}
