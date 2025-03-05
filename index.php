<?php
require_once('../../../config.php');
require_once($CFG->libdir.'/adminlib.php');

// course id
$course   = required_param('course', PARAM_INT);

$syscontext = context_system::instance();
$url = new moodle_url("/admin/tool/coursepacker/index.php");

$PAGE->set_url($url);
$PAGE->set_context($syscontext);
$PAGE->set_title('Course media packer');
$PAGE->set_heading('Detects and packages up any external media internally');

// must be admin user to access this page
require_admin();
$totalMedia = 0;
$media = [];

function queueMedia($url, $type, $id, $courseid, $tag=0) {
    global $media,$totalMedia;
    $i = array("url"=>$url, "type"=>$type, "i"=>$totalMedia, "id"=>$id, "status"=>"not started", "course"=>$courseid, "tag"=>$tag);
    $media[$i["i"]] = $i;
    $totalMedia++;
    return $i["i"];
}

function humanFileSize($size,$unit="") {
    if( (!$unit && $size >= 1<<30) || $unit == " GB")
      return number_format($size/(1<<30),2)." GB";
    if( (!$unit && $size >= 1<<20) || $unit == " MB")
      return number_format($size/(1<<20),2)." MB";
    if( (!$unit && $size >= 1<<10) || $unit == " KB")
      return number_format($size/(1<<10),2)." KB";
    return number_format($size)." bytes";
  }

function getTotalFileSize($course, $mimetype) {
    global $DB;
    $course_context = context_course::instance($course);
    $size = $DB->get_field_sql("SELECT SUM({files}.filesize) FROM {files}
    JOIN {context} ON {context}.id = {files}.contextid
    WHERE {context}.path LIKE ?
    AND {files}.mimetype=?", array($course_context->path . '%', $mimetype));
    return $size;
}

$PAGE->requires->jquery();
$PAGE->requires->js('/admin/tool/coursepacker/lib.js');

echo($OUTPUT->header());
$c = $DB->get_record('course', array('id'=>$course));
$url = $CFG->wwwroot . '/course/view.php?id=' . $course;
echo('<h2><a href="' . $url . '">' . s($c->fullname) . '<a></h2>');
$imageSize = getTotalFileSize($course, "image/jpeg");
$audioSize = getTotalFileSize($course, "audio/mp3");
echo('<p>Total size for all JPG files: ' . humanFileSize($imageSize) . '</p>');
echo('<p>Total size for all MP3 files: ' . humanFileSize($audioSize) . '</p>');
?>
<h3>Scanning for images and audio:</h3>
<table class="table">
    <tr><th>#</th><th>ID</th><th>Name</th><th>URL</th><th>Status</th></tr>
<?php
// get all media from sections
$sections = $DB->get_records_sql('SELECT * FROM {course_sections} WHERE course=?', [$course]);
foreach($sections as $s) {
    $re = '/https?:\/\/.*?\.(png|jpg|jpeg|wav|mp3|mp4|mov|avi|flv)/m';
    $count = preg_match_all($re, $s->summary, $matches, PREG_SET_ORDER, 0);
    foreach($matches as $match) {
        $url = $match[0];
        $id = queueMedia($url, "section", $s->id, $s->course);
        $name = s($s->name);
        $link = '<a href="' . $url . '" target="_blank">' . $url . '</a>';
        echo("<tr><td>$id</td><td>Section $s->id</td><td>$name</td><td>$link</td><td id='status_$id'>Not started</td></tr>");
    }
}

// get all media from lesson intros
$lessons = $DB->get_records_sql('SELECT * FROM {lesson} WHERE course=?', [$course]);
foreach($lessons as $s) {
    $re = '/https?:\/\/.*?\.(png|jpg|jpeg|wav|mp3|mp4|mov|avi|flv)/m';
    $count = preg_match_all($re, $s->intro, $matches, PREG_SET_ORDER, 0);
    foreach($matches as $match) {
        $url = $match[0];
        $id = queueMedia($url, "lesson", $s->id, $s->course);
        $name = s($s->name);
        $link = '<a href="' . $url . '" target="_blank">' . $url . '</a>';
        echo("<tr><td>$id</td><td>Lesson $s->id</td><td>$name</td><td>$link</td><td id='status_$id'>Not started</td></tr>");
    }

    // get all media from lesson pages
    $pages = $DB->get_records_sql('SELECT * FROM {lesson_pages} WHERE lessonid=?', [$s->id]);
    foreach($pages as $p) {
        $re = '/https?:\/\/.*?\.(png|jpg|jpeg|wav|mp3|mp4|mov|avi|flv)/m';
        preg_match_all($re, $p->contents, $matches, PREG_SET_ORDER, 0);
        foreach($matches as $match) {
            $url = $match[0];
            $id = queueMedia($url, "lessonpage", $p->id, $s->course, $s->id);
            $name = s($p->title);
            $link = '<a href="' . $url . '" target="_blank">' . $url . '</a>';
            echo("<tr><td>$id</td><td>Lesson page $p->id</td><td>$name</td><td>$link</td><td id='status_$id'>Not started</td></tr>");
        }
    }
}

// get all media from quizzes
$quizzes = $DB->get_records_sql('SELECT * FROM {quiz} WHERE course=?', [$course]);
foreach($quizzes as $s) {
    $re = '/https?:\/\/.*?\.(png|jpg|jpeg|wav|mp3|mp4|mov|avi|flv)/m';
    $count = preg_match_all($re, $s->intro, $matches, PREG_SET_ORDER, 0);
    foreach($matches as $match) {
        $url = $match[0];
        $id = queueMedia($url, "quiz", $s->id, $s->course);
        $name = s($s->name);
        $link = '<a href="' . $url . '" target="_blank">' . $url . '</a>';
        echo("<tr><td>$id</td><td>Quiz $s->id</td><td>$name</td><td>$link</td><td id='status_$id'>Not started</td></tr>");
    }
}

// get all media from quiz questions
$quizzes = $DB->get_records_sql('SELECT Q.*, C.id AS course, Qz.id AS quizid
FROM question Q 
    JOIN question_versions QV ON Q.id = QV.questionid
    JOIN question_bank_entries QBE ON QV.questionbankentryid = QBE.id
    JOIN question_references QR ON QBE.id = QR.questionbankentryid
    JOIN quiz_slots QS on QR.itemid = QS.id
    JOIN quiz Qz ON Qz.id=QS.quizid
    JOIN course C ON C.id=Qz.course
WHERE 
    QR.component = \'mod_quiz\'
    AND QR.questionarea = \'slot\'
    AND Qz.course=?;', [$course]);
foreach($quizzes as $s) {
    $re = '/https?:\/\/.*?\.(png|jpg|jpeg|wav|mp3|mp4|mov|avi|flv)/m';
    // question text
    $count = preg_match_all($re, $s->questiontext, $matches, PREG_SET_ORDER, 0);
    foreach($matches as $match) {
        $url = $match[0];
        $id = queueMedia($url, "questiontext", $s->id, $s->course, $s->quizid);
        $name = s($s->name);
        $link = '<a href="' . $url . '" target="_blank">' . $url . '</a>';
        echo("<tr><td>$id</td><td>Question text $s->id</td><td>$name</td><td>$link</td><td id='status_$id'>Not started</td></tr>");
    }

    // question feedback
    $count = preg_match_all($re, $s->generalfeedback, $matches, PREG_SET_ORDER, 0);
    foreach($matches as $match) {
        $url = $match[0];
        $id = queueMedia($url, "questionfeedback", $s->id, $s->course, $s->quizid);
        $name = s($s->name);
        $link = '<a href="' . $url . '" target="_blank">' . $url . '</a>';
        echo("<tr><td>$id</td><td>Question feedback $s->id</td><td>$name</td><td>$link</td><td id='status_$id'>Not started</td></tr>");
    }
}

// get all media from labels
$labels = $DB->get_records_sql('SELECT * FROM {label} WHERE course=?', [$course]);
foreach($labels as $s) {
    $re = '/https?:\/\/.*?\.(png|jpg|jpeg|wav|mp3|mp4|mov|avi|flv)/m';
    $count = preg_match_all($re, $s->intro, $matches, PREG_SET_ORDER, 0);
    foreach($matches as $match) {
        $url = $match[0];
        $id = queueMedia($url, "label", $s->id, $s->course);
        $name = s($s->name);
        $link = '<a href="' . $url . '" target="_blank">' . $url . '</a>';
        echo("<tr><td>$id</td><td>Label $s->id</td><td>$name</td><td>$link</td><td id='status_$id'>Not started</td></tr>");
    }
}

// get all media from page content
$pages = $DB->get_records_sql('SELECT * FROM {page} WHERE course=?', [$course]);
foreach($pages as $s) {
    $re = '/https?:\/\/.*?\.(png|jpg|jpeg|wav|mp3|mp4|mov|avi|flv)/m';
    $count = preg_match_all($re, $s->content, $matches, PREG_SET_ORDER, 0);
    foreach($matches as $match) {
        $url = $match[0];
        $id = queueMedia($url, "page", $s->id, $s->course);
        $name = s($s->name);
        $link = '<a href="' . $url . '" target="_blank">' . $url . '</a>';
        echo("<tr><td>$id</td><td>Page $s->id</td><td>$name</td><td>$link</td><td id='status_$id'>Not started</td></tr>");
    }

    // get all images from page intro
    $count = preg_match_all($re, $s->intro, $matches, PREG_SET_ORDER, 0);
    foreach($matches as $match) {
        $url = $match[0];
        $id = queueMedia($url, "pageintro", $s->id, $s->course);
        $link = s($s->name);
        echo("<tr><td>$id</td><td>Page Intro $s->id</td><td>$link</td><td>$url</td><td id='status_$id'>Not started</td></tr>");
    }

}
?>
</table>
<button class="btn btn-primary" id="btn-start-media">Process all media</button>
<button class="btn btn-secondary" id="btn-clear-cache">Clear page cache</button>
<div id="progress-update"></div>
<?php
echo("Total media: $totalMedia");

?>
<script>
    var media = <?php echo(json_encode($media));?>
</script>
<?php
echo($OUTPUT->footer());
?>