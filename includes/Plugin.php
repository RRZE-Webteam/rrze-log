<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

class Plugin implements PluginInterface
{
	protected $pluginFile;

	protected $basename;

	protected $directory;

	protected $url;

	protected $version;

	public function __construct(string $pluginFile)
	{
		$this->pluginFile = $pluginFile;
	}

	public function onLoaded()
	{
		$this->setBasename()
			->setDirectory()
			->setUrl()
			->setVersion();
	}

	public function getFile()
	{
		return $this->pluginFile;
	}

	public function getBasename()
	{
		return $this->basename;
	}

	public function setBasename()
	{
		$this->basename = plugin_basename($this->pluginFile);
		return $this;
	}

	public function getDirectory()
	{
		return $this->directory;
	}

	public function setDirectory()
	{
		$this->directory = rtrim(plugin_dir_path($this->pluginFile), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
		return $this;
	}

	public function getPath($path = '')
	{
		return $this->directory . ltrim($path, DIRECTORY_SEPARATOR);
	}

	public function getUrl($path = '')
	{
		return $this->url . ltrim($path, DIRECTORY_SEPARATOR);
	}

	public function setUrl()
	{
		$this->url = rtrim(plugin_dir_url($this->pluginFile), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
		return $this;
	}

	public function getVersion()
	{
		if (defined('WP_DEBUG') && WP_DEBUG) {
			return time();
		}
		return $this->version;
	}

	public function setVersion()
	{
		$headers = ['Version' => 'Version'];
		$fileData = get_file_data($this->pluginFile, $headers, 'plugin');

		if (isset($fileData['Version'])) {
			$this->version = $fileData['Version'];
		};

		return $this;
	}

	public function __call($name, $arguments)
	{
		if (!method_exists($this, $name)) {
			$class = get_class($this);
			$message = sprintf(__('Call to undefined method %1$s::%2$s', 'rrze-blocks'), $class, $name);
			do_action('rrze.log.error', $message, ['method' => $class . '::' . $name]);

			if (defined('WP_DEBUG') && WP_DEBUG) {
				throw new \Exception($message);
			}
		}
	}
}
