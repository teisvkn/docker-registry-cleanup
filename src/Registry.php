<?php

namespace Teisvkn\DockerRegistryCleanup;

class Registry {
    private $client;

	private $url;

	private $noVersionsToKeep;

	private $deleteCollection = [];

    /**
     * An instance of CLImate
     *
     * @var \League\CLImate\CLImate $climate
     */
    public $climate;

	function __construct(\League\CLImate\CLImate $climate, $url, $noVersionsToKeep ) {
		$this->url = $url;
        $this->noVersionsToKeep = $noVersionsToKeep;
        $this->client = new \GuzzleHttp\Client();
        $this->climate = $climate;
	}
	
	public function execute() {
		$data = $this->getJson('/v2/_catalog');

        foreach ($data['repositories'] as $repository) {
			$this->climate->out("{$repository}:");

			$tags = $this->tags($repository);

			$count = 0;
			foreach ($tags as $tag) {
                if ($count < $this->noVersionsToKeep) {
				    $this->climate->tab()->green($tag);
				} else {
				    $this->deleteCollection[$repository][] = $tag;
				    $this->climate->tab()->red($tag);
				}

				$count++;
			}

			$this->climate->br();
		}

        if (count($this->deleteCollection)) {
            // output delete plan
            $this->climate->br();
            $this->climate->red("Found something to delete:");
            foreach ($this->deleteCollection as $repository => $tags) {
                foreach($tags as $tag) {
                    $this->climate->tab()->red("{$repository}:{$tag}");
                }
            }
            // confirm
            $input = $this->climate->confirm('Do you really want to delete them?');
            if ($input->confirmed()) {
                foreach ($this->deleteCollection as $repository => $tags) {
                    foreach ($tags as $tag) {
                         $this->delete($repository, $tag);
                    }
                }
            }

        }
	}

	/**
	 * list tags of a repo
	 * @param string $repository
	 * @return array
	 */
	private function tags(string $repository) {
		$tagsData = $this->getJson("/v2/{$repository}/tags/list");
		if (is_array($tagsData['tags'])) {
			$tags = $tagsData['tags'];
			usort($tags, function($a, $b) {
				return version_compare(
					$this->transformTagToVersion($a), 
					$this->transformTagToVersion($b)
				);
			});
			return array_reverse($tags);
		}

		return [];
	}

	/**
	 * Transform tag values to version format,
	 * ensuring our old formats are weighted less.
	 * - master-bacs678av786vc5765as97687 becomes 0.0.0-master-bacs678av786vc5765as97687 to mark as an early version
	 * - latest becomes 99999.99999.9999-latest to mark as late version
	 * - 12 becomes 0.0.0-build-12
	 *
	 * @param string $tag
	 * @return string
	 */
	private function transformTagToVersion(string $tag) {
		if ($tag === 'latest') {
			return '99999.99999.99999-latest';
		}
		
		// a single number is prepended with 0.0.0
		if (preg_match('/^[0-9]+$/', $tag)) {
			return '0.0.0-build-' . $tag;
		}
	
		if (preg_match('/^master-.*/', $tag)) {
			return '0.0.0-' . $tag;
		}

		return $tag;
	}

	/**
	 * Does a GET request and decodes json response data.
	 *
	 * @param string $path
	 * @return array
	 */
	private function getJson($path) {
        return json_decode(
            (string)$this->client->get("{$this->url}{$path}")->getBody(),
            true
        );
	}


	/**
	 * Delete the image tag
	 *
	 * @param string $repository
	 * @param string $tag
	 * @return void
	 */
	private function delete($repository, $tag) {
        $reference = $this->getContentDigestHeader($repository, $tag);
		$this->climate->tab(2)->inline("- reference: {$reference}...");
//
//		$this->client->delete("{$this->url}/v2/{$repository}/manifests/{$reference}");
//
		$this->climate->red("DELETED");
	}


    /**
     *
     * Note from https://docs.docker.com/registry/spec/api/#deleting-an-image
     * ---
     *   When deleting a manifest from a registry version 2.3 or later,
     *   the following header must be used when HEAD or GET-ing the manifest
     *   to obtain the correct digest to delete:
     *      Accept: application/vnd.docker.distribution.manifest.v2+json"
     * ---
     *
     * @param $repository
     * @param $tag
     * @return string
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function getContentDigestHeader($repository, $tag)
    {
        $response = $this->client->head(
            "{$this->url}/v2/{$repository}/manifests/{$tag}",
            ['headers' => ['Accept' => 'application/vnd.docker.distribution.manifest.v2+json']]
        );

        $referenceHeaderName = 'Docker-Content-Digest';

        if (!$response->hasHeader($referenceHeaderName)) {
            throw new \Exception('Failed fetching the header \'' . $referenceHeaderName . '\' as reference to the tag \'' . $tag . '\'');
        }

        return $response->getHeader($referenceHeaderName)[0];
	}

}
