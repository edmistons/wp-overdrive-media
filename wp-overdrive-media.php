<?php
/**
 * Plugin Name: WP Overdrive Media
 * Description: WP Overdrive standalone module for media library management and organization
 * Plugin URI: https://wp-overdrive.com
 * Author: Edmiston[R+D]
 * Author URI: https://edmistons.com
 * Version: 1.0.16
 **/

namespace WPOverdrive\Modules;

if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly
}

class WPO_Media {
  protected static $_instance = null;
  private $rpc_id;
  private $file_ext;
  private $file_type;

	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

  public function __construct() {
    add_filter('wp_handle_upload_prefilter', [$this, 'pre_upload']);
    add_filter('wp_handle_upload', [$this, 'post_upload']);
    add_action('xmlrpc_call', [$this, 'xmlrpc_call']); //to hook into upload_dir on remote client uploads.
    add_action('admin_init', [$this, 'register_settings']);
  }

  function register_settings(){
    // License Key

    // Upload Directory Template
  	register_setting('media', 'uploads_template',
      array(
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => NULL,
      )
    );
    add_settings_field( 'uploads_template',
      'Uploads Template',
      [$this, 'uploads_template_field'],
      'media',
      'uploads'
    );
  }

  function uploads_template_field(){
    $uploads = wp_get_upload_dir();
    $value = (get_option('uploads_template')!='')?get_option('uploads_template'):'%post_type%/%parent_name%/%post_tag%';
    echo '<code>'.$uploads['baseurl'].'/</code><input type="text" name="uploads_template" class="regular-text" value="'.$value.'" />';
    echo '<p>Organize uploads into folders based on this pattern. This allows for easier management by FTP.<br/><strong>Default will save media in a folder corresponding to type and name.</strong></p><br/>';
    echo '<div class="available-structure-tags">';
    echo '<p>Available tags:';
    echo '<button class="button button-secondary" type="button">%post_type%</button>';
    echo '<button class="button button-secondary" type="button">%parent_name%</button>';
    echo '<button class="button button-secondary" type="button">%category%</button>';
    echo '<button class="button button-secondary" type="button">%post_tag%</button>';
    echo '</p>';
    echo '</div>';
    echo '<style>.available-structure-tags button {display: inline-block; margin-left: 5px!important;}</style>';
  }

  function pre_upload($file){
  	if(!empty($file['name'])){
  		$wp_filetype = wp_check_filetype($file['name']);
  		$this->file_ext = (!empty($wp_filetype['ext'])) ? $wp_filetype['ext'] : '';
  		$cthis->file_type = (!empty($wp_filetype['type'])) ? $wp_filetype['type'] : '';
  	}
  	add_filter('upload_dir', [$this, 'upload_dir']);
  	return $file;
  }

  function post_upload($fileinfo){
    remove_filter('upload_dir',  [$this, 'upload_dir']);
    return $fileinfo;
  }

  function xmlrpc_call($call){
  	if($call !== 'metaWeblog.newMediaObject'){return;}
  	global $wp_xmlrpc_server; //class-wp-xmlrpc-server.php
  	$data = $wp_xmlrpc_server->message->params[3];

  	if(!empty($data['post_id'])){
  	$this->rpc_id = (int) $data['post_id'];
  	}else{
  		$this->rpc_id = '';
  	}
  	$this->pre_upload($data);
  }


  function upload_dir($path){
  	if(!empty($path['error'])) { return $path; }
  	$customdir = $this->generate_path();
  	$path['path'] 	 = str_replace($path['subdir'], '', $path['path']); //remove default subdir (year/month)
  	$path['url']	 = str_replace($path['subdir'], '', $path['url']);
  	$path['subdir']  = $customdir;
  	$path['path'] 	.= $customdir;
  	$path['url'] 	.= $customdir;
  	return $path;
  }

  function generate_path(){
  	global $post;
  	global $post_id;
  	global $current_user;
  	$url = parse_url(wp_get_referer());
  	$queries = null;
  	if(array_key_exists('query', $url)){
  		parse_str($url['query'], $queries);
  	}
  	if(empty($post_id)){
  		if(array_key_exists('post_id', $_REQUEST)){
  			$post_id = intval($_REQUEST['post_id'], 10); //post id from post or get variables
  		}else if(is_array($queries) && array_key_exists('post', $queries)){
  			$post_id = intval($queries['post'], 10); //post id from referal URL query string
  		} else if(!empty($this->rpc_id)){
  			$post_id = $this->rpc_id; //post id from an xml rpc call. Hardly ever provided though. :/
  		}
  	}
  	$my_post;
  	if(empty($post) || (!empty($post) && is_numeric($post_id) && $post_id != $post->ID)){
  		$my_post = get_post($post_id);
  	}

    if(get_option('uploads_template')!=''){
      $customdir = get_option('uploads_template');
    }else{
      $customdir = '%post_type%/%parent_name%/%post_tag%';
    }


  	//defaults
  	$user_id = $post_type = $post_name = $author = '';
  	$time = (!empty($_SERVER['REQUEST_TIME'])) ? $_SERVER['REQUEST_TIME'] : (time() + (get_option('gmt_offset')*3600));
  	$user_id = (is_user_logged_in() && !empty($current_user)) ? $current_user->ID : '';
  	if(empty($user_id)){
  		$current_user = wp_get_current_user();
  		if($current_user instanceof WP_User){
  			$user_id = $current_user->ID;
  		}
  	}
  	if(!empty($my_post)){
  		$post_id = $my_post->ID;
  		$time = ($my_post->post_date == '0000-00-00 00:00:00') ? $time : strtotime($my_post->post_date);
  		$post_type = $my_post->post_type;
      $post_type_object = get_post_type_object($post_type)->labels;
      $post_type_plural = strtolower($post_type_object->name);
  		$author = $my_post->post_author;
  		$post_name = (!empty($my_post->post_name)) ? $my_post->post_name : (!empty($my_post->post_title) ? sanitize_title($my_post->post_title) : $post_id);
  	}else{
  		$post_id = '';
  	}

  	$date = explode(" ", date('Y m d H i s', $time));
  	$tags = array('%post_id%','%postname%','%post_type%','%year%','%monthnum%','%month%', '%day%','%hour%','%minute%','%second%', '%file_ext%', '%file_type%');
  	$replace = array($post_id, $post_name, $post_type_plural, $date[0], $date[1], $date[1], $date[2], $date[3], $date[4], $date[5], $this->file_ext, $this->file_type);

  	$customdir = str_replace($tags,	$replace, $customdir); //do all cheap replacements in one go.

  	// Change image name to match post name
  	$current_post_id = $_REQUEST['post_id'];
  	$parent_name = get_post_field( 'post_name', $current_post_id );
  	$customdir = str_replace('%parent_name%', 	$parent_name,	$customdir);

  	$matches = array();
  	if(preg_match_all('/%(.*?)%/s', $customdir, $matches) == true){
  		for($i = 0; $i < count($matches[0]); $i++){
  			if(taxonomy_exists($matches[1][$i])){
  				$customdir = str_replace($matches[0][$i], $this->get_taxonomies($post_id, $matches[1][$i]), $customdir);
  			}else{
  				$customdir = str_replace($matches[0][$i], '', $customdir);
  			}
  		}
  	}
  	$customdir = $this->leadingslashit($customdir); //for good measure.
  	$customdir = untrailingslashit($customdir);
  	while(strpos($customdir, '//') !== false){
  		$customdir = str_replace('//', '/', $customdir); //avoid duplicate slashes.
  	}
  	return apply_filters('generate_path', $customdir, $post_id);
  }

  //ripped wp_upload_dir to generate a basepath preview on the admin page.
  function wp_upload_dir( $time = null, $create_dir = true, $refresh_cache = false ) {
  	static $cache = array(), $tested_paths = array();

  	$key = sprintf( '%d-%s', get_current_blog_id(), (string) $time );

  	if ( $refresh_cache || empty( $cache[ $key ] ) ) {
  		$cache[ $key ] = _wp_upload_dir( $time );
  	}

  	/**
  	 * Filters the uploads directory data.
  	 *
  	 * @since 2.0.0
  	 *
  	 * @param array $uploads Array of upload directory data with keys of 'path',
  	 *                       'url', 'subdir, 'basedir', and 'error'.
  	 */
  	$uploads = apply_filters( 'upload_dir', $cache[ $key ] );

  	if ( $create_dir ) {
  		$path = $uploads['path'];

  		if ( array_key_exists( $path, $tested_paths ) ) {
  			$uploads['error'] = $tested_paths[ $path ];
  		} else {
  			if ( ! wp_mkdir_p( $path ) ) {
  				if ( 0 === strpos( $uploads['basedir'], ABSPATH ) ) {
  					$error_path = str_replace( ABSPATH, '', $uploads['basedir'] ) . $uploads['subdir'];
  				} else {
  					$error_path = basename( $uploads['basedir'] ) . $uploads['subdir'];
  				}

  				$uploads['error'] = sprintf(
  					/* translators: %s: directory path */
  					__( 'Unable to create directory %s. Is its parent directory writable by the server?' ),
  					esc_html( $error_path )
  				);
  			}

  			$tested_paths[ $path ] = $uploads['error'];
  		}
  	}

  	return $uploads['basedir'];
  }

  function get_parent_slug($post){
  	if(empty($post)){return '';}
  	$parent_slug = '';
  	if($post->post_parent) {
  		$parent_slug = basename(get_permalink($post->post_parent));
  	} elseif (get_post_type_object($post->post_type)->rewrite["slug"]) {
  		$parent_slug = (get_post_type_object($post->post_type)->rewrite["slug"]);
  	}
  	return $parent_slug;
  }
  function get_taxonomies($post_id, $taxonomy, $count = -1){ //deals with categories, tags or whatever else is in the terms table.
  	if($post_id === ''){return '';}
  	$terms = wp_get_object_terms($post_id, $taxonomy, array('orderby' => 'slug', 'order' => 'ASC', 'fields' => 'all'));
  	if(!is_array($terms)){return '';}

  	if($count > 0){
  		$terms = array_slice($terms, 0, $count);
  	}
  	$levels = array(0 => $terms);
  	if(is_taxonomy_hierarchical($taxonomy)){
  		$levels = $this->sort_by_levels($terms, $taxonomy);
  		$levels = array_slice($levels, 0, 1); //get rid of all levels beyond the first.
  	}
  	return $this->build_term_path($levels, $taxonomy, false);
  }
  function build_term_path($levels, $taxonomy, $flatten_hierarchy){
  	$path = '';
  	foreach($levels as $level => $terms){
  		$path .= implode('-', array_unique(wp_list_pluck($terms, 'slug'))) . '/';
  	}
  	$path = untrailingslashit($path);
  	if($flatten_hierarchy){
  		$path = str_replace('/', '-', $path);
  	}
  	return $path;
  }
  function find_leafs($levels /* array(0 => array(terms), [...], n => array(terms)) */){
  	$parents = array();
  	$leafs = array();
  	$levels = array_reverse($levels);
  	foreach($levels as $level => $terms){
  		foreach($terms as $term){
  			if(!in_array($term->term_id, $parents)){
  				if(empty($leafs[$level])){
  					$leafs[$level] = array();
  				}
  				$leafs[$level][] = $term;
  			}
  			$parents[] = $term->parent;
  		}
  	}
  	return array_reverse($leafs);
  }
  function get_parents($terms, $taxonomy){
  	$hierarcy = array();
  	foreach($terms as $term){
  		$parent_ids = get_ancestors($term->term_id, $taxonomy);
  		$parents = array();
  		if(is_array($parent_ids)){
  			foreach($parent_ids as $id){
  				$parents[] = get_term($id, $taxonomy);
  			}
  		}
  		$parents[] = $term;
  		$hierarcy = array_merge($hierarcy, $parents);
  	}
  	return $hierarcy;
  }
  function sort_by_levels($terms, $taxonomy){
  	$levels = array();
  	foreach($terms as $term){
  		$level = count(get_ancestors($term->term_id, $taxonomy));
  		if(empty($levels[$level])){
  			$levels[$level] = array();
  		}
  		$levels[$level][] = $term;
  	}
  	ksort($levels);
  	return $levels;
  }

  function get_term_parents($term_id, $taxonomy) { /*UNUSED*/
  	$parent_ids = &get_ancestors($term_id, $taxonomy);
  	if(!is_array($parent_ids)){	return '';}
  	$terms = wp_get_object_terms($parent_ids, $taxonomy); //let's hope get_objects returns them in the same order as IDs are given.
  	if(!is_array($terms)){return '';}
  	$terms = wp_list_pluck($terms, 'slug'); /*slug is the same as category_nicename*/
  	return implode('/', $terms);
  }

  function get_user_name($user_id){
  	if(!is_numeric($user_id)) {return '';}
  	$user = get_userdata($user_id);
  	if(!$user){return '';}
  	return sanitize_title($user->user_nicename);//$user->display_name
  }

  function get_user_role($user_id){
  	if(!is_numeric($user_id)) {return '';}
  	$user = get_userdata($user_id);
  	if(!$user){return '';}
  	return sanitize_title($user->roles[0]);//$user->role -> only the first one, which should be the user's main role!!!
  }

  function leadingslashit($s){
  	return ($s && $s[0] !== '/') ? '/'.$s : $s;
  }

  function sanitize_settings($options){
  	if(empty($options)){return;}
  	if(!isset($_REQUEST['submit'])){
  		return;
  	}
  	update_option('uploads_use_yearmonth_folders', isset($options['wp_use_yearmonth']));
  	$clean_options = array();
  	// $clean_options['test_ids'] = $options['test_ids'];
  	$options['upload_dir'] = $this->leadingslashit($options['upload_dir']);

  	$options['upload_dir'] = str_replace('%permalink%', '', $options['upload_dir']); //remove deprecated %permalink% settings.

  	if(get_option('uploads_use_yearmonth_folders') && stripos($options['upload_dir'], '/%year%/%monthnum%') !== 0){
  		$options['upload_dir'] = '/%year%/%monthnum%'.$options['upload_dir'];
  	}
  	$clean_options['upload_dir'] = preg_replace('/[^a-z0-9-%\/-\_]/','-',$options['upload_dir']); //allow only alphanumeric, '%', '_' and '/'
  	while(strpos($clean_options['upload_dir'], '//') !== false){
  		$clean_options['upload_dir'] = str_replace('//', '/', $clean_options['upload_dir']); //avoid duplicate slashes.
  	}
  	// $clean_options['only_leaf_nodes'] = !empty($options['only_leaf_nodes']);
  	// $clean_options['only_base_nodes'] = (!empty($options['only_base_nodes'])) && !$clean_options['only_leaf_nodes'];
  	// $clean_options['flatten_hierarchy'] = !empty($options['flatten_hierarchy']);
  	// $clean_options['all_parents'] = (!empty($options['all_parents'])) && !$clean_options['only_leaf_nodes'];
  	//print_r($options);
  	return $clean_options;
  }


}

WPO_Media::instance();

// GitHub Plugin Updates
include_once( plugin_dir_path( __FILE__ ) . 'wpo_update.php' );

$update = new \WPOverdrive\Core\WPO_Update( __FILE__ );
$update->set_username( 'edmistons' );
$update->set_repository( 'wp-overdrive-media' );

if ((string) get_option('wpo_media_license_key') !== '') {
  $update->authorize(get_option('wpo_media_license_key'));
  $update->set_repository( 'wp-overdrive-media-pro' );
}

$update->initialize();
