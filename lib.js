function request(job) {
    console.log(job);
}

var totalOriginalSize = 0;
var totalConvertedSize = 0;

function humanFileSize(size,unit="") {
    if( (!unit && size >= 1<<30) || unit == " GB")
      return (Math.round(size/(1<<30) * 100) / 100) + " GB";
    if( (!unit && size >= 1<<20) || unit == " MB")
      return (Math.round(size/(1<<20) * 100) / 100) + " MB";
    if( (!unit && size >= 1<<10) || unit == " KB")
      return (Math.round(size/(1<<10) * 100) / 100) + " KB";
    return size + " bytes";
  }

function startMedia(i, autoContinue=true) {
    $('#status_' + i.i).html('Started');
    $.getJSON('worker.php', i, (e) => {
        console.log(e);
        if(e.success) {
            processedMediaCount++;
            var progress = Math.floor(processedMediaCount / totalMediaCount * 100);
            totalOriginalSize += e.originalsize;
            totalConvertedSize += e.convertedsize;
            $("#progress-update").html("Progress: " + progress + 
                "% original file size: " + humanFileSize(totalOriginalSize) + 
                " converted file size: " + humanFileSize(totalConvertedSize));
            $('#status_' + e.i).html('<img src="success.png"> ' + e.status + " " + 
                humanFileSize(e.originalsize) + " / " + humanFileSize(e.convertedsize));

            var m = media.shift();
            if(m && autoContinue) {
                startMedia(m);
            }
        } else {
            $('#status_' + e.i).html('<img src="error.png"> ' + e.error);
        }
    });    
}

function startNextMedia() {
    var m = media.shift();
    if(m) {
        startMedia(m, false);
    }
}

function startAllMedia() {
    for(i = 0; i < 10; i++) {
        var m = media.shift();
        if(m) {
            startMedia(m);
        }
    }
}

function startOne(id) {
    var m = media[id];
    startMedia(m, false);
    delete media[id];
}

function clearCache() {
    $.getJSON('worker.php', {
        verb: 'clearcache'
    }, (e) => {
        console.log(e);
    });
}
var totalMediaCount = 0;
var processedMediaCount = 0;
$(function() {
    $('#btn-start-media').click(startAllMedia);
    totalMediaCount = media.length;

    $('#btn-clear-cache').click(clearCache);
});