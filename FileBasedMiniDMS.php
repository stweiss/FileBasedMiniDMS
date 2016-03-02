<?php
    /* 
        FileBasedMiniDMS.php    by Stefan Weiss (2016)
        #Version 0.02  02.03.2016
        
        == INSTALL
        1. Place this file on your FileServer/NAS
        2. Adjust settings for this script in "config.php" to fit your needs
        3. Create a cronjob on your FileServer/NAS to execute this script regularly.
           ex. php /volume1/home/stefan/Scans/FileBasedMiniDMS.php
           or redirect stdout to see PHP Warnings/Errors:
                php /volume1/home/stefan/Scans/FileBasedMiniDMS.php > /volume1/home/stefan/Scans/my.log 2>&1
        
        == NOTES
        This script creates a subfolder for each hashtag it finds in your filenames
        and creates a hardlink in that folder.
        Documents are expected to be stored flat in one folder. Name-structure needs
        to be like "<any name> #hashtag1 #hashtag2.extension".
        
        eg: "Documents/Scans/2015-12-25 Bill of Santa Clause #bills #2015.pdf"
        will be linked into:
            * "Documents/Scans/tags/2015/2015-12-25 Bill of Santa Clause #bills.pdf"
            * "Documents/Scans/tags/bills/2015-12-25 Bill of Santa Clause #2015.pdf"
        
        == FAQ
        Q: How do I assign another tag to my file?
        A: Simply rename the file in the $scanfolder and add the tag at the end (but
           before the extension)
        
        Q: How can I fix a typo in a documents filename?
        A: Simply rename the file in the $scanfolder. The tags are created from scratch
           at the next scheduled interval and the old links and tags are automatically
           getting removed
    */
    
    require("config.php");
    
    
    /* ------------------------------------------------------ */
    /* --- Don't touch unless you know what you're doing! --- */
    /* ------------------------------------------------------ */
    if ($logfile == "syslog") openlog("FileBasedMiniDMS", LOG_PID, LOG_USER);
    $now = new DateTime();
    $now->setTimezone(new DateTimeZone($timezone));
    
    $unusedFiles = listAllFilesInTags($tagsfolder); //all by default, remove files from array if they still exist later
    
    trace(LOG_INFO, "Scanning: $scanfolder\n");
    
    if ($handle = opendir($scanfolder)) {
        while (false !== ($entry = readdir($handle))) {
            if ($entry != "." && $entry != "..") {
                if (!is_dir("$scanfolder/$entry") &&
                        0<gettags($entry, $tags))
                {
                    foreach ($tags as $tag) {
                        if (!is_dir("$tagsfolder/$tag") &&
                            !mkdir("$tagsfolder/$tag", 0777, true))
                            trace(LOG_ERR, "ERROR: mkdir(\"$tagsfolder/$tag\", 0777, true)");
                        
                        $namewithoutthistag = preg_replace("/\s*#$tag/", "", $entry);
                        $unusedFiles = array_diff($unusedFiles, array("$tagsfolder/$tag/$namewithoutthistag"));
                        if (file_exists("$tagsfolder/$tag/$namewithoutthistag"))
                            continue;
                        
                        // symlink does not work in webdav :(
                        // copy or link (hardlink)
                        if (NULL != $namewithoutthistag &&
                            link("$scanfolder/$entry", "$tagsfolder/$tag/$namewithoutthistag"))
                            trace(LOG_INFO, "linked \"$entry\" to \"tags/$tag/$namewithoutthistag\"\n");
                        else
                            trace(LOG_ERR, "ERROR linking \"$entry\" to \"tags/$tag/$namewithoutthistag\"\n");
                    }
                }
            }
        }
        closedir($handle);
    }
    cleanUpTagFolder($unusedFiles, $tagsfolder);
    
    
    // @returns count of tags found or FALSE on error
    function gettags ($str, &$tags) {
        //preg_match("/([^#\.]+).*\.([^\.]+)$/", $str, $basenamematch);
        //$basename = trim($basenamematch[1]) . '.' . trim($basenamematch[2]);
        
        $ret = preg_match_all("/#([^\.#\s]+)/", $str, $matches);
        $tags = $matches[1];
        
        return $ret;
    }
    
    // $level should be one of LOG_DEBUG, LOG_INFO, LOG_ERR
    function trace($level, $message) {
        global $logfile, $loglevel, $now;
        
        if ($loglevel < $level) return;
        
        if ($logfile == "syslog") {
            syslog($level, $message);
        } else {
            $message = $now->format('Y-m-d H:i:s') . " " . $message;
            if ($logfile == "stdout")
                echo $message;
            else
                file_put_contents($logfile, $message, FILE_APPEND);
        }
    }
    
    function listAllFilesInTags($tagspath) {
        $result = array(); 
        if (file_exists($tagspath)) {
            $all = scandir($tagspath);
            foreach ($all as $one) {
                if ($one == "." || $one == "..") continue;
                if (is_dir($tagspath . '/' . $one))
                    $result = array_merge($result, listAllFilesInTags($tagspath . '/' . $one));
                else
                    $result[] = $tagspath . '/' . $one;
            }
        }
        return $result;
    }
    
    function cleanUpTagFolder($unusedFiles, $tagspath) {
        foreach ($unusedFiles as $file) {
            trace(LOG_INFO, "Deleting $file");
            unlink($file);
        }
        
        // now delete empty tag-folders
        $folders = scandir($tagspath);
        foreach ($folders as $folder) {
            if ($folder == "." || $folder == "..") continue;
            $f = $tagspath . '/' . $folder;
            if (is_dir($f)) {
                $items = array_diff(scandir($f), array('.','..'));
                if (count($items) == 0)
                    rmdir($f);    // rmdir won't delete not-empty folders. so simply call this on every folder. but it'll produce PHP warnings.
            }
        }
    }
?>