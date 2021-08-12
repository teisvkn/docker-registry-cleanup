<?php
require_once('vendor/autoload.php');

use Teisvkn\DockerRegistryCleanup\Registry;

$climate  = new League\CLImate\CLImate;
$climate->description('Docker Regsitry cleanup tool.');
$climate->arguments->add([
	'url' => [
		'prefix' => 'u',
		'longPrefix' => 'url',
		'description' => 'URL of the registry',
		'required' => true,
		'castTo' => 'string'
	],
	'keep' => [
		'prefix' => 'k',
		'longPrefix' => 'keep',
		'description' => 'number of versions to keep',
		'required' => true,
		'castTo' => 'int'
	],
	'delete' => [
		'longPrefix' => 'delete',
		'description' => 'delete old tags',
		'required' => false,
		'defaultValue' => false,
		'castTo' => 'bool'
	]
]);

try {
	$climate->arguments->parse();
} catch (\Exception $e) {
	$climate->usage();
	exit(1);
}

// Read the arguments and output intend
$keep = $climate->arguments->get('keep');
$url  = $climate->arguments->get('url');

$climate->out('Fetching repositories on ' . $url);
$climate->out('Will keep ' . $keep . ' latest tag(s) of each image.');

// Setup the Registry
$registry = new Registry(
	$url, 
	$keep
);

if ($climate->arguments->get('delete')) {
	$input = $climate->confirm('Do you really want to delete tags, as we go along?');
	if ($input->confirmed()) {
		$registry->enableDelete();
	}
}

// Start the show.
$registry->execute();