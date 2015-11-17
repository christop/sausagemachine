<?php

@require_once('config.inc.php');
require_once('git.inc.php');
require_once('makefile.inc.php');

/**
 *	Return the edit view
 */
function route_get_edit() {
	return render_php('view-edit.php');
}

/**
 *	Return the import view
 */
function route_get_import() {
	return render_php('view-import.php');
}

/**
 *	Return the projects view
 */
function route_get_projects() {
	return render_php('view-projects.php');
}

/**
 *	Return a list of (template) repositories
 */
function route_get_repos($param = array()) {
	$repos = config('repos', array());
	foreach ($repos as &$repo) {
		if ($repo['repo'] === config('default_repo')) {
			$repo['default'] = true;
		}
	}
	return $repos;
}

/**
 *	Return a list of Makefile targets for a (template) repository
 */
function route_get_targets($param = array()) {
	if (@is_string($param['repo'])) {
		$repo = $param['repo'];
	} else {
		$repo = config('default_repo');
	}

	$cached = get_repo_for_reading($repo);
	if ($cached === false) {
		router_internal_server_error('Cannot get ' . $repo);
	}

	$targets = make_get_targets(cache_dir($cached));
	if ($targets === false) {
		router_internal_server_error('Error getting Makefile targets for ' . $repo);
	}

	$ignore_targets = config('ignore_targets', array());
	$target_descriptions = config('target_descriptions', array());
	$default_target = config('default_target');

	for ($i=0; $i < count($targets); $i++) {
		if (in_array($targets[$i], $ignore_targets)) {
			array_splice($targets, $i, 1);
			$i--;
			continue;
		}

		$tmp = array('target' => $targets[$i]);
		if (isset($target_descriptions[$targets[$i]])) {
			$tmp['description'] = $target_descriptions[$targets[$i]];
		}
		if ($default_target === $targets[$i]) {
			$tmp['default'] = true;
		}
		$targets[$i] = $tmp;
	}

	return $targets;
}

/**
 *	Return a list of repositories created by users
 */
function route_get_user_repos($param = array()) {
	// XXX
}


function route_post_upload_files($param = array()) {
	if (@is_string($param['tmp_key'])) {
		$tmp_key = $param['tmp_key'];
	} else {
		// new temporary repository
		$repo = @is_string($param['repo']) ? $param['repo'] : config('default_repo');
		$tmp_key = get_repo($repo);
		if ($tmp_key === false) {
			router_internal_server_error('Cannot get a copy of ' . $repo);
		}
	}

	// add uploaded files
	$uploaded = array();
	foreach ($_FILES as $file) {
		$injected = inject_uploaded_file($tmp_key, $file['tmp_name'], $file['type'], $file['name']);
		// ignore errors
		if ($injected !== false) {
			$uploaded[] = $injected;
		}
	}

	return array(
		'tmp_key' => $tmp_key,
		'repo' => repo_get_url($tmp_key),
		'uploaded' => $uploaded,
		'files' => repo_get_modified_files($tmp_key)
	);
}


function route_post_convert($param = array()) {
	if (@is_string($param['tmp_key'])) {
		$tmp_key = $param['tmp_key'];
		if (@is_string($param['repo'])) {
			if (false === check_repo_switch($tmp_key, $param['repo'])) {
				router_internal_server_error('Error switching ' . $tmp_key . ' to ' . $param['repo']);
			}
		}
	} else {
		// new temporary repository
		$repo = @is_string($param['repo']) ? $param['repo'] : config('default_repo');
		$tmp_key = get_repo($repo);
		if ($tmp_key === false) {
			router_internal_server_error('Cannot get a copy of ' . $repo);
		}
	}

	// clean repository
	make_run(tmp_dir($tmp_key), 'clean');
	$after_cleaning = repo_get_modified_files($tmp_key);

	// add updated files
	$files = @is_array($param['files']) ? $param['files'] : array();
	foreach ($files as $fn => $content) {
		// ignore errors
		inject_file($tmp_key, $fn, $content);
	}

	// make target
	$target = @is_string($param['target']) ? $param['target'] : config('default_target');
	$ret = make_run(tmp_dir($tmp_key), $target, $out);
	if ($ret !== 0) {
		// return the error in JSON instead as a HTTP status code
		return array(
			'error' => $out
		);
	}

	// established modified files
	$modified = repo_get_modified_files($tmp_key);
	$generated = array();
	foreach ($modified as $fn) {
		if (in_array($fn, $after_cleaning)) {
			// file existed earlier
			continue;
		}
		if (in_array($fn, array_keys($files))) {
			// we uploaded this ourselves
			continue;
		}
		$generated[] = $fn;
	}

	return array(
		'tmp_key' => $tmp_key,
		'repo' => repo_get_url($tmp_key),
		'target' => $target,
		'generated' => $generated,
		'files' => $modified
	);
}


function inject_uploaded_file($tmp_key, $fn, $mime = NULL, $orig_fn = '') {
	// many relevant file formats still arive as "application/octet-stream"
	// so ignore the MIME type for now, and focus solely on the extension
	// of the original filename we got from the browser
	$ext = strtolower(filext($orig_fn));

	switch ($ext) {
		case 'css':
			// CSS
			$target = 'epub/custom.css';
			break;
		case 'docx':
			// Word document
			$target = 'docx/' . basename($orig_fn);
			// XXX: instant conversion? (also return "generated" in this case)
			break;
		case 'gif':
		case 'png':
		case 'jpeg':
		case 'jpg':
			// Image
			if (basename($orig_fn, '.' . $ext) == 'cover') {
				// special case for the cover image
				// delete any existing one
				$epub_dir = @scandir(tmp_dir($tmp_key) . '/epub');
				if (is_array($epub_dir)) {
					foreach ($epub_dir as $fn) {
						if (in_array($fn, array('cover.gif', 'cover.png', 'cover.jpeg', 'cover.jpg'))) {
							@unlink(tmp_dir($tmp_key) . '/epub/' . $fn);
						}
					}
				}
				$target = 'epub/' . basename($orig_fn);
			} else {
				$target = 'md/imgs/ ' . basename($orig_fn);
			}
			break;
		case 'md':
			// Markdown
			$target = 'md/' . basename($orig_fn);
			break;
		case 'otf':
		case 'tty':
		case 'woff':
		case 'woff2':
			// Font
			$target = 'lib/' . basename($orig_fn);
			break;
		default:
			$target = false;
			break;
	}

	if ($target === false) {
		// not supported
		return false;
	}

	// create files and directories as permissive as possible
	$old_umask = @umask(0000);

	// make sure the containing directory exists
	$pos = strrpos('/', $target);
	if ($pos !== false) {
		@mkdir(tmp_dir($tmp_key) . '/' . substr($target, 0, $pos), 0777, true);
	}

	// move file to final location
	$ret = @move_uploaded_file($fn, tmp_dir($tmp_key) . '/' . $target);

	@umask($old_umask);

	return ($ret) ? $target : false;
}


function inject_file($tmp_key, $fn, $content) {
	// XXX: implement
	return false;
}


function check_repo_switch($tmp_key, $repo) {
	// XXX: implement
	return true;
}


function handle_repo_switch($tmp_key, $new_repo, &$uploaded = array()) {
	$staging = get_repo($new_repo);
	if ($staging === false) {
		router_internal_server_error('Cannot get ' . $repo);
	}
	// copy the files over
	for ($i=0; $i < count($uploaded); $i++) {
		// XXX: umask?
		if (false === @copy(tmp_dir($tmp_key) . '/' . $uploaded[$i], tmp_dir($staging) . '/' . $uploaded[$i])) {
			// ignore error, but remove from list
			array_splice($uploaded, $i, 1);
			$i--;
		}
	}
	// delete the original repository in tmp
	if (false === rm_recursive(tmp_dir($tmp_key))) {
		router_internal_server_error('Cannot delete ' . $tmp_key);
	}
	// move staging to the previous location
	if (false === @rename(tmp_dir($staging), tmp_dir($tmp_key))) {
		router_internal_server_error('Cannot rename ' . $staging . ' to ' . $tmp_key);
	}
}
