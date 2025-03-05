<?php
header('Content-Type: application/json; charset=utf-8');
require_once('../../../config.php');
require_once($CFG->libdir.'/adminlib.php');
$result = array("success"=>false);

function download_file($url, $filename) {
    $tmpfilename = tempnam(sys_get_temp_dir(), $filename);
    $file = fopen($tmpfilename, "wb");
    $data = file_get_contents($url);
    if($data) {
        fwrite($file, $data);
    } else {
        $tmpfilename = "";
    }
    fclose($file);
    return $tmpfilename;
}

function convertmp3($filename, $quality) {
    $result = `ffmpeg -h`;
    // only attempt to convert it ffmpeg is installed
    if(str_contains($result, "usage")) {
        $outfilename = tempnam(sys_get_temp_dir(), $filename) . ".mp3";
        $output = `ffmpeg -i $filename -codec:a libmp3lame -b:a $quality $outfilename`;
        return $outfilename;
    }     
    return $filename;
}

function hasAlpha($filename) {
    $imgdata = imagecreatefrompng($filename);
    $w = imagesx($imgdata);
    $h = imagesy($imgdata);

    if($w>50 || $h>50){ //resize the image to save processing if larger than 50px:
        $thumb = imagecreatetruecolor(10, 10);
        imagealphablending($thumb, FALSE);
        imagecopyresized( $thumb, $imgdata, 0, 0, 0, 0, 10, 10, $w, $h );
        $imgdata = $thumb;
        $w = imagesx($imgdata);
        $h = imagesy($imgdata);
    }
    //run through pixels until transparent pixel is found:
    for($i = 0; $i<$w; $i++) {
        for($j = 0; $j < $h; $j++) {
            $rgba = imagecolorat($imgdata, $i, $j);
            if(($rgba & 0x7F000000) >> 24) return true;
        }
    }
    return false;
}


function convert_to_jpg($filename) {
    $image = imagecreatefrompng($filename);
    $bg = imagecreatetruecolor(imagesx($image), imagesy($image));
    imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
    imagealphablending($bg, TRUE);
    imagecopy($bg, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
    imagedestroy($image);
    $quality = 85; // 0 = worst / smaller file, 100 = better / bigger file 
    imagejpeg($bg, $filename, $quality);
    imagedestroy($bg);
}

function add_file($filename, $fileinfo) {
    global $DB;
    $existing = $DB->get_record_sql("SELECT * FROM {files} WHERE contenthash=? AND contextid=? AND filename=?", [sha1_file($filename), $fileinfo["contextid"], $fileinfo['filename']]);
    if($existing) {
        return "Already in file database";
    } else {
        $fs = get_file_storage();
        $newfile = $fs->create_file_from_pathname($fileinfo, $filename);
        return "Added";
    }

}

if(is_siteadmin()) {
    $verb = optional_param('verb', 'download', PARAM_TEXT);
    $i = optional_param('i', 0, PARAM_INT);
    $id = optional_param('id', 0, PARAM_INT);
    $course = optional_param('course', 0, PARAM_INT);
    $type = optional_param('type', '', PARAM_TEXT);
    $result["i"] = $i;
    session_write_close();
    
    switch($verb) {
        case 'clearcache':
            purge_caches();
            $result['success'] = true;
            break;
        

        case 'download':
            $url = optional_param('url', '', PARAM_URL);
            switch($type) {                
                case 'section':
                    $context = context_course::instance($course);
                    $result["context"] = $context->id;
                    if(preg_match("/https?:\/\/.*\/(.*)\.(png|jpg|jpeg|wav|mp3|mp4|mov|avi|flv)/", $url, $matches) && $id > 0 && $course > 0) {
                        
                        $filename = $matches[1];
                        $fileextension = $matches[2];

                        // download file
                        $tmpfilename = download_file($url, $filename);
                        if($tmpfilename == "") {
                            $result["error"] = "Could not download image";
                            break;
                        }

                        $result['originalsize'] = filesize($tmpfilename);                        
                        
                        // convert to jpg if necessary
                        if($fileextension == "png") {
                            if(hasAlpha($tmpfilename)) {
                                $result['hasalpha'] = true;
                            } else {
                                $result['hasalpha'] = false;
                                convert_to_jpg($tmpfilename);
                                $fileextension = "jpg";
                            }
                        }

                        // convert mp3 if necessary
                        if($fileextension == "mp3") {
                            $tmpfilename = convertmp3($tmpfilename, "64k");
                        }
                        clearstatcache();

                        $result['convertedsize'] = filesize($tmpfilename);

                        $fileinfo = [
                            'contextid' => $context->id,    
                            'component' => 'course',        
                            'filearea'  => 'section',       
                            'itemid'    => $id,              
                            'filepath'  => '/',            
                            'filename'  => $id . "_" . $filename . '.' . $fileextension
                        ];

                        // check if file already exists
                        $result['status'] = add_file($tmpfilename, $fileinfo);

                        // delete tmp file
                        unlink($tmpfilename);

                        // update URL in activity
                        $activity = $DB->get_record_sql("SELECT * FROM {course_sections} WHERE course=? AND id=?", [$course, $id]);
                        if($activity) {
                            $activity->summary = str_replace($url, "@@PLUGINFILE@@/" . $id . "_" . $filename . "." . $fileextension, $activity->summary);
                            $DB->update_record('course_sections', $activity);
                        }
                        $result["success"] = true;
                    } else {
                        $result["error"] = "Invalid data";
                    }
                    break;

                    case 'lesson':
                        $cm = get_coursemodule_from_instance('lesson', $id, $course);
                        $context = get_context_instance(CONTEXT_MODULE, $cm->id);
                        $result["context"] = $context->id;
                        if(preg_match("/https?:\/\/.*\/(.*)\.(png|jpg|jpeg|wav|mp3|mp4|mov|avi|flv)/", $url, $matches) && $id > 0 && $course > 0) {
                            
                            $filename = $matches[1];
                            $fileextension = $matches[2];
    
                            // download file
                            $tmpfilename = download_file($url, $filename);
                            if($tmpfilename == "") {
                                $result["error"] = "Could not download image";
                                break;
                            }
    
                            $result['originalsize'] = filesize($tmpfilename);                        
                            
                            // convert to jpg if necessary
                            if($fileextension == "png") {
                                if(hasAlpha($tmpfilename)) {
                                    $result['hasalpha'] = true;
                                } else {
                                    $result['hasalpha'] = false;
                                    convert_to_jpg($tmpfilename);
                                    $fileextension = "jpg";
                                }
                            }

                            // convert mp3 if necessary
                            if($fileextension == "mp3") {
                                $tmpfilename = convertmp3($tmpfilename, "64k");
                            }
                            clearstatcache();
    
                            $result['convertedsize'] = filesize($tmpfilename);
    
                            $fileinfo = [
                                'contextid' => $context->id,    
                                'component' => 'mod_lesson',        
                                'filearea'  => 'intro',       
                                'itemid'    => 0,              
                                'filepath'  => '/',            
                                'filename'  => $id . "_" . $filename . '.' . $fileextension
                            ];
    
                            // check if file already exists
                            $result['status'] = add_file($tmpfilename, $fileinfo);
    
                            // delete tmp file
                            unlink($tmpfilename);
    
                            // update URL in activity
                            $activity = $DB->get_record_sql("SELECT * FROM {lesson} WHERE course=? AND id=?", [$course, $id]);
                            if($activity) {
                                $activity->intro = str_replace($url, "@@PLUGINFILE@@/" . $id . "_" . $filename . "." . $fileextension, $activity->intro);
                                $DB->update_record('lesson', $activity);
                            }
                            $result["success"] = true;
                        } else {
                            $result["error"] = "Invalid data";
                        }
                        break;

                    case 'lessonpage':
                        $lesson = optional_param('tag', 0, PARAM_INT);
                        $cm = get_coursemodule_from_instance('lesson', $lesson, $course);
                        $context = get_context_instance(CONTEXT_MODULE, $cm->id);
                        $result["context"] = $context->id;
                        if(preg_match("/https?:\/\/.*\/(.*)\.(png|jpg|jpeg|wav|mp3|mp4|mov|avi|flv)/", $url, $matches) && $id > 0 && $course > 0) {
                            
                            $filename = $matches[1];
                            $fileextension = $matches[2];
    
                            // download file
                            $tmpfilename = download_file($url, $filename);
                            if($tmpfilename == "") {
                                $result["error"] = "Could not download image";
                                break;
                            }
    
                            $result['originalsize'] = filesize($tmpfilename);                        
                            
                            // convert to jpg if necessary
                            if($fileextension == "png") {
                                if(hasAlpha($tmpfilename)) {
                                    $result['hasalpha'] = true;
                                } else {
                                    $result['hasalpha'] = false;
                                    convert_to_jpg($tmpfilename);
                                    $fileextension = "jpg";
                                }
                            }

                            // convert mp3 if necessary
                            if($fileextension == "mp3") {
                                $tmpfilename = convertmp3($tmpfilename, "64k");
                            }
                            clearstatcache();
    
                            $result['convertedsize'] = filesize($tmpfilename);
    
                            $fileinfo = [
                                'contextid' => $context->id,    
                                'component' => 'mod_lesson',        
                                'filearea'  => 'page_contents',       
                                'itemid'    => $id,              
                                'filepath'  => '/',            
                                'filename'  => $id . "_" . $filename . '.' . $fileextension
                            ];
    
                            // check if file already exists
                            $result['status'] = add_file($tmpfilename, $fileinfo);
    
                            // delete tmp file
                            unlink($tmpfilename);
    
                            // update URL in activity
                            $activity = $DB->get_record_sql("SELECT * FROM {lesson_pages} WHERE id=?", [$id]);
                            if($activity) {
                                $activity->contents = str_replace($url, "@@PLUGINFILE@@/" . $id . "_" . $filename . "." . $fileextension, $activity->contents);
                                $DB->update_record('lesson_pages', $activity);
                            }
                            $result["success"] = true;
                        } else {
                            $result["error"] = "Invalid data";
                        }
                        break;
                    
                    case 'quiz':
                        $cm = get_coursemodule_from_instance('quiz', $id, $course);
                        $context = get_context_instance(CONTEXT_MODULE, $cm->id);
                        $result["context"] = $context->id;
                        if(preg_match("/https?:\/\/.*\/(.*)\.(png|jpg|jpeg|wav|mp3|mp4|mov|avi|flv)/", $url, $matches) && $id > 0 && $course > 0) {
                            
                            $filename = $matches[1];
                            $fileextension = $matches[2];
    
                            // download file
                            $tmpfilename = download_file($url, $filename);
                            if($tmpfilename == "") {
                                $result["error"] = "Could not download image";
                                break;
                            }
    
                            $result['originalsize'] = filesize($tmpfilename);                        
                            
                            // convert to jpg if necessary
                            if($fileextension == "png") {
                                if(hasAlpha($tmpfilename)) {
                                    $result['hasalpha'] = true;
                                } else {
                                    $result['hasalpha'] = false;
                                    convert_to_jpg($tmpfilename);
                                    $fileextension = "jpg";
                                }
                            }

                            // convert mp3 if necessary
                            if($fileextension == "mp3") {
                                $tmpfilename = convertmp3($tmpfilename, "64k");
                            }
                            clearstatcache();
    
                            $result['convertedsize'] = filesize($tmpfilename);
    
                            $fileinfo = [
                                'contextid' => $context->id,    
                                'component' => 'mod_quiz',        
                                'filearea'  => 'intro',       
                                'itemid'    => 0,              
                                'filepath'  => '/',            
                                'filename'  => $id . "_" . $filename . '.' . $fileextension
                            ];
    
                            // check if file already exists
                            $result['status'] = add_file($tmpfilename, $fileinfo);
    
                            // delete tmp file
                            unlink($tmpfilename);
    
                            // update URL in activity
                            $activity = $DB->get_record_sql("SELECT * FROM {quiz} WHERE course=? AND id=?", [$course, $id]);
                            if($activity) {
                                $activity->intro = str_replace($url, "@@PLUGINFILE@@/" . $id . "_" . $filename . "." . $fileextension, $activity->intro);
                                $DB->update_record('quiz', $activity);
                            }
                            $result["success"] = true;
                        } else {
                            $result["error"] = "Invalid data";
                        }
                        break;
            
                    case 'questiontext':
                        $context = context_course::instance($course);
                        $result["context"] = $context->id;
                        if(preg_match("/https?:\/\/.*\/(.*)\.(png|jpg|jpeg|wav|mp3|mp4|mov|avi|flv)/", $url, $matches) && $id > 0 && $course > 0) {
                            
                            $filename = $matches[1];
                            $fileextension = $matches[2];
    
                            // download file
                            $tmpfilename = download_file($url, $filename);
                            if($tmpfilename == "") {
                                $result["error"] = "Could not download image";
                                break;
                            }
    
                            $result['originalsize'] = filesize($tmpfilename);                        
                            
                            // convert to jpg if necessary
                            if($fileextension == "png") {
                                if(hasAlpha($tmpfilename)) {
                                    $result['hasalpha'] = true;
                                } else {
                                    $result['hasalpha'] = false;
                                    convert_to_jpg($tmpfilename);
                                    $fileextension = "jpg";
                                }
                            }

                            // convert mp3 if necessary
                            if($fileextension == "mp3") {
                                $tmpfilename = convertmp3($tmpfilename, "64k");
                            }
                            clearstatcache();
    
                            $result['convertedsize'] = filesize($tmpfilename);
    
                            $fileinfo = [
                                'contextid' => $context->id,    
                                'component' => 'question',        
                                'filearea'  => 'questiontext',       
                                'itemid'    => $id,              
                                'filepath'  => '/',            
                                'filename'  => $id . "_" . $filename . '.' . $fileextension
                            ];
    
                            // check if file already exists
                            $result['status'] = add_file($tmpfilename, $fileinfo);
    
                            // delete tmp file
                            unlink($tmpfilename);
    
                            // update URL in activity
                            $activity = $DB->get_record_sql("SELECT * FROM {question} WHERE id=?", [$id]);
                            if($activity) {
                                $activity->questiontext = str_replace($url, "@@PLUGINFILE@@/" . $id . "_" . $filename . "." . $fileextension, $activity->questiontext);
                                $DB->update_record('question', $activity);
                            }
                            $result["success"] = true;
                        } else {
                            $result["error"] = "Invalid data";
                        }
                        break;
                
                    case 'questionfeedback':
                        $context = context_course::instance($course);
                        $result["context"] = $context->id;
                        if(preg_match("/https?:\/\/.*\/(.*)\.(png|jpg|jpeg|wav|mp3|mp4|mov|avi|flv)/", $url, $matches) && $id > 0 && $course > 0) {
                            
                            $filename = $matches[1];
                            $fileextension = $matches[2];
    
                            // download file
                            $tmpfilename = download_file($url, $filename);
                            if($tmpfilename == "") {
                                $result["error"] = "Could not download image";
                                break;
                            }
    
                            $result['originalsize'] = filesize($tmpfilename);                        
                            
                            // convert to jpg if necessary
                            if($fileextension == "png") {
                                if(hasAlpha($tmpfilename)) {
                                    $result['hasalpha'] = true;
                                } else {
                                    $result['hasalpha'] = false;
                                    convert_to_jpg($tmpfilename);
                                    $fileextension = "jpg";
                                }
                            }

                            // convert mp3 if necessary
                            if($fileextension == "mp3") {
                                $tmpfilename = convertmp3($tmpfilename, "64k");
                            }
                            clearstatcache();
    
                            $result['convertedsize'] = filesize($tmpfilename);
    
                            $fileinfo = [
                                'contextid' => $context->id,    
                                'component' => 'question',        
                                'filearea'  => 'generalfeedback',       
                                'itemid'    => $id,              
                                'filepath'  => '/',            
                                'filename'  => $id . "_" . $filename . '.' . $fileextension
                            ];
    
                            // check if file already exists
                            $result['status'] = add_file($tmpfilename, $fileinfo);
    
                            // delete tmp file
                            unlink($tmpfilename);
    
                            // update URL in activity
                            $activity = $DB->get_record_sql("SELECT * FROM {question} WHERE id=?", [$id]);
                            if($activity) {
                                $activity->generalfeedback = str_replace($url, "@@PLUGINFILE@@/" . $id . "_" . $filename . "." . $fileextension, $activity->generalfeedback);
                                $DB->update_record('question', $activity);
                            }
                            $result["success"] = true;
                        } else {
                            $result["error"] = "Invalid data";
                        }
                        break;

                case 'label':
                    $cm = get_coursemodule_from_instance('label', $id, $course);
                    $context = get_context_instance(CONTEXT_MODULE, $cm->id);
                    $result["context"] = $context->id;
                    if(preg_match("/https?:\/\/.*\/(.*)\.(png|jpg|jpeg|wav|mp3|mp4|mov|avi|flv)/", $url, $matches) && $id > 0 && $course > 0) {
                        $filename = $matches[1];
                        $fileextension = $matches[2];

                        // download file
                        $tmpfilename = download_file($url, $filename);
                        if($tmpfilename == "") {
                            $result["error"] = "Could not download image";
                            break;
                        }

                        $result['originalsize'] = filesize($tmpfilename);                        
                    
                        // convert to jpg if necessary
                        if($fileextension == "png") {
                            if(hasAlpha($tmpfilename)) {
                                $result['hasalpha'] = true;
                            } else {
                                $result['hasalpha'] = false;
                                convert_to_jpg($tmpfilename);
                                $fileextension = "jpg";
                            }
                        }

                        // convert mp3 if necessary
                        if($fileextension == "mp3") {
                            $tmpfilename = convertmp3($tmpfilename, "64k");
                        }
                        clearstatcache();

                        $result['convertedsize'] = filesize($tmpfilename);

                        $fileinfo = [
                            'contextid' => $context->id,    
                            'component' => 'mod_label',        
                            'filearea'  => 'intro',       
                            'itemid'    => 0,              
                            'filepath'  => '/',            
                            'filename'  => $id . "_" . $filename . '.' . $fileextension
                        ];

                        // check if file already exists
                        $result['status'] = add_file($tmpfilename, $fileinfo);

                        // delete tmp file
                        unlink($tmpfilename);

                        // update URL in activity
                        $activity = $DB->get_record_sql("SELECT * FROM {label} WHERE course=? AND id=?", [$course, $id]);
                        if($activity) {
                            $activity->intro = str_replace($url, "@@PLUGINFILE@@/" . $id . "_" . $filename . "." . $fileextension, $activity->intro);
                            $DB->update_record('label', $activity);
                        }
                        $result["success"] = true;
                    } else {
                        $result["error"] = "Invalid data";
                    }
                    break;

                    case 'pageintro':
                        $cm = get_coursemodule_from_instance('page', $id, $course);
                        $context = get_context_instance(CONTEXT_MODULE, $cm->id);
                        $result["context"] = $context->id;
                        if(preg_match("/https?:\/\/.*\/(.*)\.(png|jpg|jpeg|wav|mp3|mp4|mov|avi|flv)/", $url, $matches) && $id > 0 && $course > 0) {
                            $filename = $matches[1];
                            $fileextension = $matches[2];
    
                            // download file
                            $tmpfilename = download_file($url, $filename);
                            if($tmpfilename == "") {
                                $result["error"] = "Could not download image";
                                break;
                            }
    
                            $result['originalsize'] = filesize($tmpfilename);                        
                    
                            // convert to jpg if necessary
                            if($fileextension == "png") {
                                if(hasAlpha($tmpfilename)) {
                                    $result['hasalpha'] = true;
                                } else {
                                    $result['hasalpha'] = false;
                                    convert_to_jpg($tmpfilename);
                                    $fileextension = "jpg";
                                }
                            }

                            // convert mp3 if necessary
                            if($fileextension == "mp3") {
                                $tmpfilename = convertmp3($tmpfilename, "64k");
                            }
                            clearstatcache();

                            $result['convertedsize'] = filesize($tmpfilename);

                            $fileinfo = [
                                'contextid' => $context->id,    
                                'component' => 'mod_page',        
                                'filearea'  => 'intro',       
                                'itemid'    => 0,              
                                'filepath'  => '/',            
                                'filename'  => $id . "_" . $filename . '.' . $fileextension
                            ];
    
                            // check if file already exists
                            $result['status'] = add_file($tmpfilename, $fileinfo);
    
                            // delete tmp file
                            unlink($tmpfilename);
    
                            // update URL in activity
                            $activity = $DB->get_record_sql("SELECT * FROM {page} WHERE course=? AND id=?", [$course, $id]);
                            if($activity) {
                                $activity->intro = str_replace($url, "@@PLUGINFILE@@/" . $id . "_" . $filename . "." . $fileextension, $activity->intro);
                                $DB->update_record('page', $activity);
                            }                                
                            $result["success"] = true;
                        } else {
                            $result["error"] = "Invalid data";
                        }
                        break;

                    case 'page':
                        $cm = get_coursemodule_from_instance('page', $id, $course);
                        $context = get_context_instance(CONTEXT_MODULE, $cm->id);
                        $result["context"] = $context->id;
                        if(preg_match("/https?:\/\/.*\/(.*)\.(png|jpg|jpeg|wav|mp3|mp4|mov|avi|flv)/", $url, $matches) && $id > 0 && $course > 0) {
                            
                            $filename = $matches[1];
                            $fileextension = $matches[2];
    
                            // download file
                            $tmpfilename = download_file($url, $filename);
                            if($tmpfilename == "") {
                                $result["error"] = "Could not download image";
                                break;
                            }
    
                            $result['originalsize'] = filesize($tmpfilename);                        
                    
                            // convert to jpg if necessary
                            if($fileextension == "png") {
                                if(hasAlpha($tmpfilename)) {
                                    $result['hasalpha'] = true;
                                } else {
                                    $result['hasalpha'] = false;
                                    convert_to_jpg($tmpfilename);
                                    $fileextension = "jpg";
                                }
                            }

                            // convert mp3 if necessary
                            if($fileextension == "mp3") {
                                $tmpfilename = convertmp3($tmpfilename, "64k");
                            }
                            clearstatcache();

                            $result['convertedsize'] = filesize($tmpfilename);


                            $fileinfo = [
                                'contextid' => $context->id,    
                                'component' => 'mod_page',        
                                'filearea'  => 'content',       
                                'itemid'    => 0,              
                                'filepath'  => '/',            
                                'filename'  => $id . "_" . $filename . '.' . $fileextension
                            ];
    
                            // check if file already exists
                            $result['status'] = add_file($tmpfilename, $fileinfo);
    
                            // delete tmp file
                            unlink($tmpfilename);
    
                            // update URL in activity
                            $activity = $DB->get_record_sql("SELECT * FROM {page} WHERE course=? AND id=?", [$course, $id]);
                            if($activity) {
                                $activity->content = str_replace($url, "@@PLUGINFILE@@/" . $id . "_" . $filename . "." . $fileextension, $activity->content);
                                $DB->update_record('page', $activity);
                            }                                
                            $result["success"] = true;
                        } else {
                            $result["error"] = "Invalid data";
                        }
                        break;
            }
            
            
            break;
    }
    
} else {
    $result["error"] = "Not logged in";
}
echo(json_encode($result));
?>