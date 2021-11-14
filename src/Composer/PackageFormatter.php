<?php

namespace dnj\ComposerGateway\Composer;

use dnj\ComposerGateway\Gitlab;
use Illuminate\Support\Facades\Cache;
use stdClass;

class PackageFormatter
{
    protected Gitlab\Project $gitlab;

    public function __construct(Gitlab\Project $gitlab)
    {
        $this->gitlab = $gitlab;
    }

    /**
     * @return array{"packages":array<mixed>}
     */
    public function buildPackages(?string $namespace = null, ?string $project = null, ?string $package = null): array
    {
        $fullPath = "{$namespace}/{$project}";
        $projects = $this->gitlab->findProjectsWithPackages($namespace, $project, $package);
        $response = [
            'packages' => [],
        ];
        foreach ($projects as $project) {
            $response['packages'] = array_merge(
                $response['packages'],
                $this->formatPackages($project, $project->packages->nodes)
            );
        }

        return $response;
    }

    /**
     * @param stdClass[] $packages
     *
     * @return array<string,array<string,mixed>>
     */
    public function formatPackages(stdClass $project, array $packages): array
    {
        $packagesByName = [];
        foreach ($packages as $package) {
            /**
             * @var string
             */
            $name = $package->name;

            /**
             * @var string
             */
            $version = $package->version;
            if (!isset($packagesByName[$name])) {
                $packagesByName[$name] = [];
            }
            $packagesByName[$name][$version] = $package;
        }
        foreach ($packagesByName as &$versions) {
            foreach ($versions as &$version) {
                $version = $this->formatPackageVersion($project, $version);
            }
        }

        return $packagesByName;
    }

    /**
     * @return array<string,mixed>
     */
    protected function formatPackageVersion(stdClass $project, stdClass $package): array
    {
        $composer = $this->getComposerJsonFromRepo($project->fullPath, $package->metadata->targetSha);
        $composer['name'] = $package->name;
        $composer['version'] = $package->version;
        foreach (['type', 'license'] as $key) {
            if (isset($package->metadata->composerJson->{$key})) {
                $composer[$key] = $package->metadata->composerJson->{$key};
            }
        }
        $composer['source'] = [
            'type' => 'git',
            'url' => $project->httpUrlToRepo,
            'reference' => $package->metadata->targetSha,
        ];
        $projectID = Gitlab\Project::getNumericID($project->id);
        $composer['dist'] = [
            'type' => 'zip',
            'url' => $this->gitlab->getInstanceUrl()."/api/v4/projects/{$projectID}/packages/composer/archives/{$package->name}.zip?sha={$package->metadata->targetSha}",
            'reference' => $package->metadata->targetSha,
            'shasum' => '',
        ];

        return $composer;
    }

    /**
     * @return array<mixed>
     */
    protected function getComposerJsonFromRepo(string $project, string $targetSha)
    {
        return Cache::rememberForever("{$project}@{$targetSha}/composer.json", function () use ($project, $targetSha) {
            $result = $this->gitlab->getProjectBlobs($project, ['composer.json'], $targetSha);

            return isset($result['composer.json']->rawBlob) ? json_decode($result['composer.json']->rawBlob, true) : null;
        });
    }
}
