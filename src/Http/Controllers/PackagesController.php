<?php

namespace dnj\ComposerGateway\Http\Controllers;

use dnj\ComposerGateway\Composer;
use dnj\ComposerGateway\Gitlab;
use Illuminate\Http\Request;

class PackagesController extends Controller
{
    protected function getGitlab(Request $request): Gitlab\Project
    {
        $instanceUrl = config('app.gitlab-instance-url', 'https://gitlab.com');

        return new Gitlab\Project($instanceUrl, [
            'Authorization' => strval($request->header('Authorization')),
            'Private-Token' => strval($request->header('Private-Token')),
        ]);
    }

    /**
     * @return mixed
     */
    public function packages(Request $request, ?string $path = null, ?string $vendor = null, ?string $package = null)
    {
        if ($path) {
            $path = explode('/', $path);
            $project = count($path) > 1 ? $path[count($path) - 1] : null;
            $namespace = implode('/', array_slice($path, 0, max(count($path) - 1, 1)));
        } else {
            $namespace = null;
            $project = null;
        }

        $packageName = ($vendor and $package) ? "{$vendor}/{$package}" : null;
        $api = $this->getGitlab($request);
        $formatter = new Composer\PackageFormatter($api);

        return $formatter->buildPackages($namespace, $project, $packageName);
    }
}
