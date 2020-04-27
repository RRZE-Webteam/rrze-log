<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

interface PluginInterface
{
	public function getFile();

	public function getBasename();

	public function setBasename();

	public function getDirectory();

	public function setDirectory();

	public function getPath(string $path);

	public function getUrl(string $path);

	public function setUrl();

	public function getVersion();

	public function setVersion();
}
