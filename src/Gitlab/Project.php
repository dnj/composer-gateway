<?php

namespace dnj\ComposerGateway\Gitlab;

use Exception;
use GraphQL\Client;
use GraphQL\InlineFragment;
use GraphQL\Query;
use GraphQL\RawObject;
use GraphQL\Variable;

class Project
{
    public static function getNumericID(string $globalID): int
    {
        if (!preg_match("/^gid:\/\/gitlab\/Project\/(\d+)$/i", $globalID, $matches)) {
            throw new Exception('Invalid global ID');
        }

        return intval($matches[1]);
    }

    protected static function getPackagesQuery(): Query
    {
        $composerJsonQuery = (new Query('composerJson'))
            ->setSelectionSet(['name', 'type', 'version', 'license']);

        $composerMetaDataFragment = (new InlineFragment('ComposerMetadata'))
            ->setSelectionSet(['targetSha', $composerJsonQuery]);

        $packagesNodesQuery = (new Query('nodes'))
            ->setSelectionSet([
                'id',
                'name',
                'version',
                (new Query('metadata'))
                    ->setSelectionSet([$composerMetaDataFragment]),
            ]);
        $packagesPageInfo = (new Query('pageInfo'))
            ->setSelectionSet([
                'hasNextPage',
                'endCursor',
            ]);

        $packagesQuery = (new Query('packages'))
            ->setArguments([
                'status' => new RawObject('DEFAULT'),
                'packageType' => new RawObject('COMPOSER'),
                'after' => '$afterPackage',
                'packageName' => '$packageName',
            ])
            ->setSelectionSet([$packagesNodesQuery, $packagesPageInfo]);

        return $packagesQuery;
    }

    protected static function getProjectQuery(): Query
    {
        return (new Query('project'))
            ->setVariables([
                new Variable('fullPath', 'ID', true),
                new Variable('afterPackage', 'String', false),
                new Variable('packageName', 'String', false),
            ])
            ->setArguments(['fullPath' => '$fullPath'])
            ->setSelectionSet([
                'id',
                'fullPath',
                'httpUrlToRepo',
                'webUrl',
                self::getPackagesQuery(),
            ]);
    }

    protected static function getProjectsQuery(): Query
    {
        $projectsPageInfo = (new Query('pageInfo'))
            ->setSelectionSet([
                'hasNextPage',
                'endCursor',
            ]);
        $projectsNodes = (new Query('nodes'))
            ->setSelectionSet([
                'id',
                'fullPath',
                'httpUrlToRepo',
                'webUrl',
                self::getPackagesQuery(),
            ]);

        return (new Query('projects'))
            ->setVariables([
                new Variable('afterProject', 'String', false),
                new Variable('afterPackage', 'String', false),
                new Variable('packageName', 'String', false),
            ])
            ->setArguments(['membership' => false, 'after' => '$afterProject'])
            ->setSelectionSet([
                $projectsNodes,
                $projectsPageInfo,
            ]);
    }

    protected static function getProjectsInNamespaceQuery(): Query
    {
        $projectsQuery = self::getProjectsQuery();
        $projectsQuery->setArguments([
            'after' => '$afterProject',
            'includeSubgroups' => true,
        ]);

        return (new Query('namespace'))
            ->setVariables([
                new Variable('namespace', 'ID', true),
                new Variable('afterProject', 'String', false),
                new Variable('afterPackage', 'String', false),
                new Variable('packageName', 'String', false),
            ])
            ->setArguments(['fullPath' => '$namespace'])
            ->setSelectionSet([$projectsQuery]);
    }

    protected static function getProjectBlobsQuery(): Query
    {
        $blobQuery = (new Query('nodes'))
            ->setSelectionSet(['path', 'rawBlob']);

        $blobsQuery = (new Query('blobs'))
            ->setArguments(['paths' => '$paths', 'ref' => '$ref'])
            ->setSelectionSet([$blobQuery]);

        $repositoryQuery = (new Query('repository'))
            ->setSelectionSet([$blobsQuery]);

        return (new Query('project'))
            ->setVariables([
                new Variable('fullPath', 'ID', true),
                new Variable('paths', '[String!]', true),
                new Variable('ref', 'String', false),
            ])
            ->setArguments(['fullPath' => '$fullPath'])
            ->setSelectionSet([
                'id',
                'fullPath',
                $repositoryQuery,
            ]);
    }

    protected Client $api;
    protected string $instanceUrl;

    /**
     * @var array<string,string>
     */
    protected array $headers;

    /**
     * @param array<string,string> $headers
     */
    public function __construct(string $instanceUrl, array $headers = [])
    {
        $this->instanceUrl = $instanceUrl;
        $this->headers = $headers;
        $this->api = new Client($this->instanceUrl.'/api/graphql', $headers);
    }

    public function getInstanceUrl(): string
    {
        return $this->instanceUrl;
    }

    /**
     * @return array<string,string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * @return \stdClass[]
     */
    public function findProjectsWithPackages(?string $namespace = null, ?string $project = null, ?string $packageName = null)
    {
        if (!$namespace and !$project) {
            $gql = self::getProjectsQuery();
            $fullPath = null;
            $packageName = null;
        } elseif ($namespace and !$project) {
            $gql = self::getProjectsInNamespaceQuery();
            $fullPath = null;
            $packageName = null;
        } elseif (!$namespace and $project) {
            throw new Exception('Impossible condition');
        } else {
            $gql = $this->getProjectQuery();
            $fullPath = "{$namespace}/{$project}";
        }
        $projects = [];
        $afterProject = null;
        while (true) {
            $result = $this->api->runQuery($gql, false, [
                'fullPath' => $fullPath,
                'packageName' => $packageName,
                'afterProject' => $afterProject,
                'namespace' => $namespace,
            ]);
            /**
             * @var \stdClass
             */
            $data = $result->getData();
            if (isset($data->namespace->projects)) {
                $data->projects = $data->namespace->projects;
            }
            if (isset($data->projects)) {
                array_push($projects, ...$data->projects->nodes);
                $afterProject = $data->projects->pageInfo->endCursor;
                if (!$data->projects->pageInfo->hasNextPage) {
                    break;
                }
            } elseif (isset($data->project)) {
                $projects = [$data->project];
                break;
            } else {
                break;
            }
        }

        return $projects;
    }

    /**
     * @param string[] $paths
     *
     * @return array<string,string|null>
     */
    public function getProjectBlobs(string $fullPath, array $paths, ?string $ref = null): array
    {
        $gql = self::getProjectBlobsQuery();
        $result = $this->api->runQuery($gql, false, [
            'fullPath' => $fullPath,
            'paths' => $paths,
            'ref' => $ref,
        ]);
        /**
         * @var \stdClass
         */
        $data = $result->getData();
        $blobs = [];
        foreach ($paths as $path) {
            $blob = null;
            foreach ($data->project->repository->blobs->nodes as $item) {
                if ($item->path == $path) {
                    $blob = $item;
                    break;
                }
            }
            $blobs[$path] = $blob;
        }

        return $blobs;
    }
}
