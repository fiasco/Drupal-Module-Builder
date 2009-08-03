<?php
// $Id$

/**
 * Drupal commandline interactive library
 *
 * @author Josh Waihi (http://geek.joshwaihi.com)
 * @param path  Drupal's absolute root directory in local file system (optional).
 * @param URI   A URI to execute, including HTTP protocol prefix.
 */

/**
 * Parse the arguments passed to the script
 */
function args($key) {
  static $args;
  if ($args != NULL) {
    return $args[$key];
  }
  $args = array();

  // Get rid of the first argument, its the script name.
  $args['script'] = basename(array_shift($_SERVER['argv']));
  while ($param = array_shift($_SERVER['argv'])) {
    if (strpos($param, '--') !== FALSE) {
      $param = str_replace('--', '', $param);
      // If the key is there than set the value true.
      $args[$param] = TRUE;
      if (!$value = array_shift($_SERVER['argv'])) {
        break;
      }
      $args[$param] = $value;
    }
  }
  return isset($args[$key]) ? $args[$key] : FALSE;
}

/**
 * Wrapper for printing information out to the user.
 */
function notify($msg) {
  $lines = explode("\n", $msg);
  foreach ($lines as $line) {
    print "    $line\n";
  }
}

/**
 * Wrapper function for asking questions to the user.
 * 
 * The idea is to later add support so libreadline is not required.
 */
function ask_question($question) {
  return readline($question);
}

/**
 * Ask a required question the the user.
 */
function require_answer($question) {
  $value = ask_question($question);
  if (empty($value)) {
    notify("This is a required value. Please answer provide a value.");
    return require_answer($question);
  }
  return $value;
}

/**
 * Helper function to parse strings to arrays
 */
function _string_to_array($str) {
  $array = explode(' ', $str);
  foreach ($array as &$chunk) {
    $chunk = trim($chunk);
    $chunk = strtr($chunk, array(',' => ''));
    if (empty($chunk)) {
      unset($chunk);
    }
  }   
  return $array;
}

/**
 * Drizzle Cli hook tool
 */
function drupal_cli_invoke($hook, $lib) {
  $function = $lib . '_' . $hook;
  $args = func_get_args();
  array_shift($args);
  array_shift($args);
  return call_user_func_array($function, $args);
}

/**
 * Bootstrap Drupal
 */
function drupal_cli_bootstrap() {
  if (!defined('DRUPAL_ROOT')) {
    die("No Drupal root found. Exiting.\n");
  }
  include_once DRUPAL_ROOT . '/includes/bootstrap.inc';
  drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);
}

/**
 * Supply user with command prompt
 */
function drupal_cli_prompt() {
  // If there is no protocol in the http, add one.
  if (!strpos($_SERVER['HTTP_HOST'], '://')) {
    $_SERVER['HTTP_HOST'] = 'http://' . $_SERVER['HTTP_HOST'];
  }
  $prompt = array_shift(explode('.', parse_url($_SERVER['HTTP_HOST'], PHP_URL_HOST))) . ' >> ';
  $command = '';
  readline_read_history(HISTORY_FILE);
  while (TRUE) {
    $line = readline($prompt);
    if (!drupal_cli_prepare($line)) {
      if (!empty($line)) {
        $return = eval($line);
      }
      $command = '';
    }
    else {
      $command .= trim($line);
      if (substr($line,-1) != ';') {
        continue;
      }
      $return = eval($command);
      $command = '';
      if (is_bool($return)) {
        $return = ($return ? 'true' : 'false');
      }
      elseif (is_array($return) || is_object($return)) {
        $return = print_r($return,1);
      }
    }
    if (!empty($return)) {
      notify($return);
    }
  }
}

function drupal_cli_prepare(&$line) {
  static $tools;
  if (!isset($tools)) {
    global $drizzle_libs;
    $tools = array();
    foreach ($drizzle_libs as $lib) {
      $return = drupal_cli_invoke('tools', $lib);
      if (is_array($return)) {
        $tools += $return;
      }
    }
  }
  readline_add_history($line);
  // Check if this is a tool.
  if (substr($line, 0, 1) == '\\') {
    list($tool, $command) = explode(' ', $line, 2);
    if (isset($tools[$tool]) && function_exists($tools[$tool])) {
      $line = call_user_func_array($tools[$tool], array($command));
      return false;
    }
    else {
      notify("no utility found for $tool");
    }
  }
  return true;
}

/**
 * Implementation of hook_tools
 */
function cli_tools() {
  return array(
    '\q' => 'drupal_cli_exit',
    '\h' => 'drupal_cli_help',
    '\e' => 'drupal_cli_function_exists',
    '\d' => 'drupal_cli_dump',
    '\f' => 'drupal_cli_include_file',
  );
}

/**
 * Implementation of hook_help
 */
function cli_help() {
  return array(
    '\q' => 'Exit Drizzle.',
    '\h' => 'Help: Displays this help page.',
    '\e' => 'Exists: checks if function exists: \e drupal_set_message',
    '\d' => 'Dump: Dump the return information from a function: \d print_r($_SERVER,1)',
    '\f' => 'File: include a file relative to document root or absoulte path',
  );
}

/**
 * Exit Drizzle
 */
function drupal_cli_exit() {
  if (!file_exists(HISTORY_FILE)) { 
    touch(HISTORY_FILE);
    chmod(HISTORY_FILE, 0777);
  }
  if (!file_exists(HISTORY_FILE) || !readline_write_history(HISTORY_FILE)) {
    notify("Can't save history to " . HISTORY_FILE);
  }
  die('Drizzle session ended.' . "\n");
}

/**
 * Drizzle help message
 */
function drupal_cli_help() {
  global $drizzle_libs;
  $guide = array();
  foreach ($drizzle_libs as $lib) {
    $help = drupal_cli_invoke('help', $lib);
    if (is_array($help)) {
      $guide += $help;
    }
  }
  notify('Help:');
  foreach ($guide as $key => $help) {
    notify("  $key: $help");
  }
}

/**
 * Cli tool to check if a function exists
 */
function drupal_cli_function_exists($function) {
  $function = trim($function);
  $functions = explode(' ', $function);
  foreach ($functions as $function) {
    if (function_exists($function)) {
      notify($function . ': function exists.');
    }
    else {
      notify($function . ': no such function loaded.');
    }
  }
}

/**
 * Dump variable data
 * 
 * Expect that the parameter is a variable.
 */
function drupal_cli_dump($var) {
  if (substr($var, -1) == ';') {
    $var = substr($var, 0, -1);
  }
  // Because $var is a string, we can't figure out which variable the var/function is.
  return "_drupal_cli_dump($var);";
}

/**
 * Helper function for drupal_cli_dump
 */
function _drupal_cli_dump($var) {
  $type = gettype($var);
  switch ($type) {
    case 'boolean':
      notify("($type) " . ($var ? 'TRUE' : 'FALSE'));
    break;
    case 'integer':
    case 'double':
    case 'string':
      notify("($type) " . $var);    
    break;
    case 'array':
    case 'object':
    case 'resource':
      notify(print_r($var,1));
    break;
    case 'NULL':
      notify("($type) " . 'NULL');
    break;
  }
}

/**
 * Include a file quickly.
 */
function drupal_cli_include_file($file) {
  $file = trim($file);
  if (!file_exists($file)) {
    notify("Cannot include $file. File doesn't exist.");
    return;
  }
  include $file;
}
