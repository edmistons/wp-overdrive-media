<?php
namespace WPOverdrive\Core;

class WPO_Update {
	private $file;
	private $plugin;
	private $basename;
	private $active;
	private $username;
	private $repository;
	private $authorize_token;
	private $github_release;
	private $github_contributors;

	public function __construct($file) {
			$this->file = $file;
			add_action('admin_init', [$this, 'set_plugin_properties']);

			return $this;
	}

	public function initialize() {
			$this->get_repository_info();
			add_filter('pre_set_site_transient_update_plugins', [$this, 'modify_transient'], 10, 1);
			add_filter('plugins_api', [$this, 'plugin_popup'], 10, 3);
			add_filter('upgrader_post_install', [$this, 'after_install'], 10, 3);

			add_filter('all_plugins', function ($plugins) {
				if ( isset($plugins[$this->basename]) ) {
					$plugins[$this->basename]['slug'] = $this->basename;
				}
				return $plugins;
			});
	}


	public function set_plugin_properties() {
			$this->plugin = get_plugin_data($this->file);
			$this->basename = plugin_basename($this->file);
			$this->active = is_plugin_active($this->basename);
	}

	public function set_username($username) {
			$this->username = $username;
	}

	public function set_repository($repository) {
			$this->repository = $repository;
	}

	public function authorize($token) {
			$this->authorize_token = $token;
	}

	private function get_repository_info() {
			if (is_null($this->github_release)) {
					$request_uri = sprintf('https://api.github.com/repos/%s/%s/releases', $this->username, $this->repository);

					// Switch to HTTP Basic Authentication for GitHub API v3
					$curl = curl_init();

					curl_setopt_array($curl, [
							CURLOPT_URL => $request_uri,
							CURLOPT_RETURNTRANSFER => true,
							CURLOPT_ENCODING => "",
							CURLOPT_MAXREDIRS => 10,
							CURLOPT_TIMEOUT => 0,
							CURLOPT_FOLLOWLOCATION => true,
							CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
							CURLOPT_CUSTOMREQUEST => "GET",
							CURLOPT_HTTPHEADER => [
									"User-Agent: WP-Overdrive"
							]

					]);

					if ($this->authorize_token) {
						curl_setopt_array($curl, [
							CURLOPT_HTTPHEADER => [
									"Authorization: token " . $this->authorize_token
							]
						]);
					}

					$response = curl_exec($curl);
					curl_close($curl);

					$response = json_decode($response, true);

					if (is_array($response)) {
							$response = current($response);
					}


					$this->github_release = $response;
			}

			// contributors
			if (is_null($this->$github_contributors)) {
					$request_uri = sprintf('https://api.github.com/repos/%s/%s/contributors', $this->username, $this->repository);

					// Switch to HTTP Basic Authentication for GitHub API v3
					$curl = curl_init();

					curl_setopt_array($curl, [
							CURLOPT_URL => $request_uri,
							CURLOPT_RETURNTRANSFER => true,
							CURLOPT_ENCODING => "",
							CURLOPT_MAXREDIRS => 10,
							CURLOPT_TIMEOUT => 0,
							CURLOPT_FOLLOWLOCATION => true,
							CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
							CURLOPT_CUSTOMREQUEST => "GET",
							CURLOPT_HTTPHEADER => [
									"User-Agent: WP-Overdrive"
							]

					]);

					if ($this->authorize_token) {
						curl_setopt_array($curl, [
							CURLOPT_HTTPHEADER => [
									"Authorization: token " . $this->authorize_token
							]
						]);
					}

					$response = curl_exec($curl);
					curl_close($curl);

					$response = json_decode($response, true);
					$this->$github_contributors = $response;
			}
	}

	public function modify_transient($transient) {
			if (property_exists($transient, 'checked')) {
					if ($checked = $transient->checked) {
							$this->get_repository_info();

							$out_of_date = version_compare($this->github_release['tag_name'], $checked[$this->basename], 'gt');

							if ($out_of_date) {
									$new_files = $this->github_release['zipball_url'];
									$slug = current(explode('/', $this->basename));

									$plugin = [
											'url' => $this->plugin['PluginURI'],
											'slug' => $slug,
											'package' => $new_files,
											'new_version' => $this->github_release['tag_name']
									];

									$transient->response[$this->basename] = (object) $plugin;
							}
					}
			}

			return $transient;
	}

	public function plugin_popup($result, $action, $args) {
			if ($action !== 'plugin_information') {
					return false;
			}

			// do nothing if it is not our plugin
			if( $args->slug !== $this->basename && $args->slug != current( explode( '/' , $this->basename ) ) ) {
				return false;
			}

			if (!empty($args->slug)) {
					if ($args->slug == $this->basename || $args->slug == current( explode( '/' , $this->basename ) ) ) { //current(explode('/' , $this->basename))) {
							$this->get_repository_info();

							$plugin = [
									'name' => $this->plugin['Name'],
									'slug' => $this->basename,
									'tested' => '5.6',
									'version' => $this->github_release['tag_name'],
									'author' => '<a href="'.$this->plugin['AuthorURI'].'" target="_blank">'.$this->plugin['AuthorName'].'</a>',
									'author_profile' => $this->plugin['AuthorURI'],
									'last_updated' => $this->github_release['published_at'],
									'homepage' => $this->plugin['PluginURI'],
									'short_description' => $this->plugin['Description'],
									'download_link' => $this->github_release['zipball_url']
							];

							// TODO: Look in assets folder for screenshots and parse image data
							// $plugin['sections']['Screenshots'] =  '<ol><li><a href="IMG_URL" target="_blank"><img src="IMG_URL" alt="CAPTION" /></a><p>CAPTION</p></li></ol>';

							$plugin['sections']['Overview'] = 'Overview';

							if ($this->github_release['body']!=''){
								$plugin['sections']['Updates'] = $this->github_release['body'];
							}

							$plugin['sections']['FAQ'] = 'FAQ';

							$plugin['sections']['Changelog'] = 'Changelog';

							// // // Debug
							// $ghr = var_export($this->$github_contributors,true);
							// $plugin['sections']['GitHub'] = '<pre>'.$ghr.'</pre>';

							// Contributors needs to be separate GH request
							foreach($this->$github_contributors as $contributor){
								$plugin['contributors'][] = [
									'display_name'	=> $contributor['login'],
									'profile'				=> $contributor['html_url'],
									'avatar'				=> $contributor['avatar_url'],
								];
							}

							$plugin['donate_link'] = 'https://wp-overdrive.com/donate';


							$plugin['banners'] = array(
								'low' => plugin_dir_url( __FILE__ ).'/assets/banner.png',
								'high' => plugin_dir_url( __FILE__ ).'/assets/banner.png'
							);
							return (object) $plugin;
					}
			}

			return $result;
	}

	public function after_install($response, $hook_extra, $result) {
			global $wp_filesystem;

			$install_directory = plugin_dir_path($this->file);
			$wp_filesystem->move($result['destination'], $install_directory);
			$result['destination'] = $install_directory;

			if ($this->active) {
					activate_plugin($this->basename);
			}

			return $result;
	}
}
