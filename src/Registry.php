<?php

namespace Teisvkn\DockerRegistryCleanup;

class Registry {
	private $registryUrl;

	private $isDeleteEnabled = false;

	private $noVersionsToKeep;

	function __construct($registryUrl, $noVersionsToKeep) {
		$this->url = $registryUrl;
        $this->noVersionsToKeep = $noVersionsToKeep;
	}

	public function enableDelete() {
		$this->isDeleteEnabled = true;
	}

	public function execute() {
		$data = $this->getJson("/v2/_catalog");

        foreach ($data['repositories'] as $repository) {
			echo $repository . ":" . PHP_EOL;

			$tags = $this->tags($repository);

			$count = 0;
			foreach ($tags as $tag) {
				echo ' - ' . $tag;
				if ($count < $this->noVersionsToKeep) {
					echo ' (keep)';
				} else {
					echo ' (delete)';
					if ($this->isDeleteEnabled === true) {
						$this->delete($repository, $tag);
					}
				}
				echo PHP_EOL;

				$count++;
			}

			echo PHP_EOL;
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
	 * @param array $headers
	 * @return array
	 */
	private function getJson($path, $headers = []) {
		return json_decode($this->request($path, 'GET', $headers), true);
	}

	/**
	 * Does a request
	 *
	 * @param string $path
	 * @param string $method
	 * @param array $headers
	 * @return string
	 */
	private function request($path, $method = 'GET', $headers = []) {
		return file_get_contents("{$this->url}{$path}", false, $this->streamContext($headers, $method));
	}

	/**
	 * Delete the image tag
	 *
	 * @param string $repository
	 * @param string $tag
	 * @return void
	 */
	private function delete($repository, $tag) {
		$manifestHeaders  = get_headers("{$this->url}/v2/{$repository}/manifests/{$tag}", 1, $this->streamContext(
			['Accept: application/vnd.docker.distribution.manifest.v2+json'], 
			'HEAD'
		));
		$reference = $manifestHeaders['Docker-Content-Digest'];
		echo "    - reference: {$reference}...";
		// $this->request("/v2/{$repository}/manifests/{$reference}", 'DELETE');
		echo "DELETED" . PHP_EOL;
		// var_dump($this->request("/v2/{$repository}/manifests/{$reference}", 'GET'));
	}


	private function streamContext($headers, $method = 'GET') {
		return stream_context_create([
			'http' => [
				'header' => implode('\r\n', $headers),
				'method' => $method
			]
		]);
	}
}
