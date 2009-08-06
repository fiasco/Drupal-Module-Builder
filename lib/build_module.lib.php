<?php

/**
 * Drupal Module Builder
 * 
 * Simple command line package to help create the base of a new module
 * 
 * @author Josh Waihi (http://geek.joshwaihi.com/)
 */

define('API_LIB', DRIZZLE . '/apis');

/**
 * Implementation of hook_tools
 */
function build_module_tools() {
  return array(
    '\mb' => 'build_module',
  );
}

/**
 * Help
 */
function build_module_help() {
  return array(
    '\mb' => 'Module Builder: Launch the module builder,',
  );
  
  /*$file = basename(__FILE__);
  return "
  Drupal Module Builder
  
  $file helps create quick drupal module skeletons to save you a lot
  of typing. It creates .info, .install and .module files inside a 
  folder that you sepcify the location of. $file allows you to specify
  which hooks you want to use and provides tab completetion for them.
  
  Usage: 
    $file --core [CORE]
      - Create a module for version [CORE] of Drupal (numeric: 5,6 or 7). Defaults to 6
    $file --help
      - Show this help message
  Parameters:
    --core      which major version of core the hooks apply too.
    --api-lib   define an alternative api lib to read hooks from.

";*/
}

/**
 * Get a list of supported hooks
 */
function build_module_get_hooks() {
  static $hooks = array();
  if (!empty($hooks)) {
    return $hooks;
  }
  if (!$core = (int) args('core')) {
    $core = 6;
  }
  $LIB = API_LIB . '/' . $core;
  if (args('api-lib')) {
    $LIB = args('api-lib');
  }
  if (!is_dir($LIB)) {
    return array('Diretory ' . $LIB . ' does not exist.');
  }
  if (!$handle = opendir($LIB)) {
    $hooks = array();
    return $hooks;
  }
  while (false !== ($file = readdir($handle))) {
      $file = file_get_contents($LIB . '/' . $file);
      if (!preg_match_all('/function hook_([^\(\s]+)\(([^\)]*)\)/mi', $file, $matches)) {
        continue;
      }
      foreach ($matches[1] as $idx => $hook) {
        $hooks[$hook] = $matches[2][$idx];
      }
  }
  closedir($handle);
  return $hooks;
}

/**
 *  Tab completetion for hooks
 */
function build_module_hook_tab_completetion($search_str) {
  if (empty($search_str)) {
    return array_keys(build_module_get_hooks());
  }
  $matches = array();
  foreach (array_keys(build_module_get_hooks()) as $hook) {
    if (strpos($hook, $search_str) !== FALSE) {
      $matches[] = $hook;
    }
  }
  if (empty($matches)) {
    return array($search_str);
  }
  return $matches;
}

/**
 * Build information about the new mdoule
 */
function build_module_get_module_info() {
  $info = array(
    'name' => require_answer("What is the title of your module?: "),
    'description' => ask_question("Write a summary of what your module does: "),
    'package' => require_answer("What package is your module apart of? Use 'Other' if your unsure: "),
    'dependencies' => ask_question("Please list any dependencies seperated by a space e.g menu taxonomy views: "),
  );
  if (!empty($info['dependencies'])) {
    $info['dependencies'] = _string_to_array($info['dependencies']);
  }
  return $info;
}

/**
 * Get Drupal Hooks user wishes to implement.
 */
function build_module_get_implemented_hooks() {
  readline_completion_function('build_module_hook_tab_completetion');
  $hooks = readline("Please add any hooks you want to implement, you can add more later. Seperate them with spaces: ");
  // Revert back to normal.
  readline_completion_function('drupal_cli_tab_complete');
  if (empty($hooks)) {
    return array();
  }
  $hooks = _string_to_array($hooks);
  return $hooks;
}

/**
 * Get the location of the where to place the module.
 */
function build_module_get_module_location() {
  $root = exec('pwd');
  $location = ask_question("Where do you want to place this Module? [$root] ");
  if (empty($location)) {
    $location = $root;
  }
  if (!is_dir($location) && !mkdir($location)) {
    notify("Cannot create module in $location. Permission Denied.");
    return build_module_get_module_location();
  }
  return $location;
}

/**
 * Build the Module.
 */
function build_module() {
  // Create the module folder
  $name = require_answer("What is the machine name of your module?: ");
  $dir = build_module_get_module_location() . "/$name";
  if (!is_dir($dir) && !mkdir($dir)) {
    die("Cannot create module $name because $dir already exists or you do not have the permission to create the directory.\n");
  }
  
  // Create the .info file
  $info = '';
  foreach (build_module_get_module_info() as $key => $value) {
    if (is_array($value)) {
      foreach ($value as $val) {
        $info .= "{$key}[] = $val" . PHP_EOL;
      }
    }
    else {
      $info .= "$key = $value" . PHP_EOL;
    }
  }
  $info .= 'core = ' . CORE . PHP_EOL;
  $info .= 'version = ' . CORE . '-1.x-dev' . PHP_EOL;
  file_put_contents($dir . "/$name.info", $info);
  
  //Create .module and .install files
  $hooks = build_module_get_hooks();
  foreach (build_module_get_implemented_hooks() as $hook) {
    $file = in_array($hook, array('install', 'uninstall', 'schema', 'requirements')) ? 'install' : 'module';
    $function  = PHP_EOL;
    $function .= '/**' . PHP_EOL;
    $function .= ' * Implementation of hook_' . $hook . PHP_EOL;
    $function .= ' */' . PHP_EOL;
    $args = isset($hooks[$hook]) ? $hooks[$hook] : '';
    $function .= "function {$name}_{$hook} ($args) {" . PHP_EOL . PHP_EOL;
    $function .= '}' . PHP_EOL;
    if (!file_exists("$dir/$name.$file")) {
      file_put_contents("$dir/$name.$file", '<?php ' . PHP_EOL);
    }
    file_put_contents("$dir/$name.$file", $function, FILE_APPEND | LOCK_EX);
  }
  notify("Module '$name' created in $dir.");
}

