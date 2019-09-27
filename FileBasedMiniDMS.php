<?php
    /* 
        FileBasedMiniDMS.php    by Stefan Weiss (2017-2019)
    */
    $version = "0.15";
    
    require(dirname(__FILE__) . "/config.php");
    
    
    /* ------------------------------------------------------ */
    /* --- Don't touch unless you know what you're doing! --- */
    /* ------------------------------------------------------ */
    $testmode = false;
    $ocrtotxt = false;
    
    $options = getopt("vdtohxl::");
    foreach ($options as $opt => $val) {
        switch ($opt) {
            case "v": // verbose
                $loglevel = 6;
                break;
            case "d": // debug
                $loglevel = 7;
                break;
            case "l": //logfile
                $logfile = empty($val)?"stdout":$val;
                break;
            case "t": // test mode, no actions
                $testmode = true;
                break;
            case "o": // ocr all
                $OCRPrefix = "";
                break;
            case "x":
                $ocrtotxt = true;// store OCR'ed text in txt-files
                break;
            case "h": // help
                print("FileBasedMiniDMS v$version\n");
                print("syntax: php FileBasedMiniDMS.php <options>\n");
                print("  -v          verbose (loglevel 6)\n");
                print("  -d          debug (loglevel 7)\n");
                print("  -l<file>    log to given filename. if no filename is given, logs are sent to stdout.\n");
                print("  -t          test mode, no modifications to files\n");
                print("  -x          save ocr'ed text to txt-file\n");
                print("  -o          perform OCR on all files in \$inboxfolder.\n");
                print("              (can be useful after the rules were changed and can be combined with -t)\n");
                exit(0);
        }
    }
    
    touch($inboxfolder . "/.FbmDMS_is_active");
    if ($logfile == "syslog") openlog("FileBasedMiniDMS", LOG_PID, LOG_USER);
    $now = new DateTime();
    $now->setTimezone(new DateTimeZone($timezone));
    
    if ($doOCR) {
        trace(LOG_DEBUG, "Scanning for new scans: $inboxfolder\n");
        $newscans = listAllFiles($inboxfolder);
        
        foreach ($newscans as $scan) {
            $scanpath_parts = pathinfo($scan);
            if (0 != strcasecmp("pdf", $scanpath_parts['extension']))
                continue;
            
            // skip empty files
            if (filesize($scan) == 0)
                continue;
            
            // skip already OCR'ed files based on $OCRPrefix
            if ($OCRPrefix &&
                fnmatch($OCRPrefix . '*', $scanpath_parts['filename'], FNM_CASEFOLD))
                continue;
            
            // OCR new pdf's
            if (fnmatch($matchWithoutOCR, $scanpath_parts['filename'], FNM_CASEFOLD))
            {
                $ocrfilename = getOCRfilename($scan);
                $user_id = exec('stat -c "%u" "'. $scan .'"');
                if (!is_numeric($user_id))
                {
                    trace(LOG_ERROR, "Could not get uid of file $scan\n");
                    continue;
                }
                $cmd = "docker run --name ocr --rm -i $dockercontainer $ocropt - - <\"$scan\" 2>&1 >\"$ocrfilename\"";
                trace(LOG_DEBUG, "Run Docker: $cmd\n");
                
                unset($dockeroutput);
                $dockerret = 0;
                if (!$testmode) exec($cmd, $dockeroutput, $dockerret);
                if ($dockerret == 0) {
                    trace(LOG_INFO, "OCR'd \"$scan\" with status $dockerret\n");
                    trace(LOG_DEBUG, "Docker output:\n " . implode("\n ", $dockeroutput) . "\n");
                    if (!$testmode)
                    {
                        // preserve: mode,ownership,timestamps
                        exec("cp -p --attributes-only \"$scan\" \"$ocrfilename\"");
                        recyclefile($inboxfolder, $scan);
                    }
                } else {
                    trace(LOG_ERR, "Docker output:\n " . implode(" \n", $dockeroutput) . "\n");
                }
                
                $scan = $ocrfilename;
                $scanpath_parts = pathinfo($scan);
            }
            
            // Rename new PDF's based on rules
            if ($doRenameAfterOCR &&
                fnmatch($OCRPrefix . '*', $scanpath_parts['filename'], FNM_CASEFOLD))
            {
                unset($out);
                unset($namedate);
                // get text from first page only
                $cmd = "pdftotext -l 1 \"$scan\" - 2>&1";
                trace(LOG_DEBUG, "run: $cmd\n");
                
                if ($ocrtotxt) exec("pdftotext -l 1 \"$scan\" 2>&1");
                exec($cmd, $out, $ret);
                if ($ret == 0) {
                    trace(LOG_DEBUG, "pdftotext output:\n " . implode("\n ", $out) . "\n");
                    
                    // == rename rules
                    $namedate = findPdfDate($out, $scan);
                    // name: default should be original filename without starting-date and without hashtags
                    $namename = findPdfSubject($out, stripDateAndTags($scanpath_parts['filename']));
                    
                    $tags = array();
                    gethashtags($scanpath_parts['filename'], $tags); // get tags from source filename and keep them
                    findPdfTags($out, $tags);
                    foreach($tags as &$tag) {
                        $tag = strtolower($tag);
                    }
                    $tags = array_unique($tags);
                    $nametags = "";
                    if (count($tags) > 0) {
                        // get tags from source filename and keep them
                        $nametags = " " . implode(" ", $tags);
                    }
                    
                    // == do rename
                    $newname = $scanpath_parts['dirname'] . "/$namedate " . $namename . "$nametags." . $scanpath_parts['extension'];
                    
                    if ($newname == $scan) {
                        trace(LOG_DEBUG, "rename not required: $scan\n");
                        continue;
                    }
                    
                    $newname = getNextFreeFilename($newname);
                    trace(LOG_INFO, "Renaming $scan\n");
                    trace(LOG_INFO, "      to $newname\n");
                    if (!$testmode && !rename($scan, $newname))
                    {
                        trace(LOG_ERR, "Could not rename '$scan' to '$newname'\n");
                    }
                } else {
                    trace(LOG_ERR, "pdftotext output:\n " . implode(" \n", $out) . "\n");
                }
            }
        }
    
    }
    
    if ($doTagging) {
        trace(LOG_INFO, "Scanning for Tagging: $archivefolder\n");
        $unusedFiles = listAllFiles($tagsfolder); //all by default, remove files from array if they still exist later
        
        if ($handle = opendir($archivefolder)) {
            while (false !== ($entry = readdir($handle))) {
                if ($entry != "." && $entry != ".." &&
                    !is_dir("$archivefolder/$entry") &&
                    0<gettags($entry, $tags))
                {
                    // Process Hashtags
                    foreach ($tags as $tag) {
                        if (!is_dir("$tagsfolder/$tag") &&
                            !$testmode &&
                            !mkdir("$tagsfolder/$tag", 0777, true))
                            trace(LOG_ERR, "ERROR: mkdir(\"$tagsfolder/$tag\", 0777, true)\n");
                        
                        $namewithoutthistag = preg_replace("/\s*#$tag/", "", $entry);
                        if (NULL != $namewithoutthistag) {
                            $unusedFiles = array_diff($unusedFiles, array("$tagsfolder/$tag/$namewithoutthistag"));
                            if (file_exists("$tagsfolder/$tag/$namewithoutthistag"))
                                continue;
                            
                            // symlink does not work in webdav :(
                            // copy or link (hardlink)
                            trace(LOG_INFO, "linking \"$entry\" to \"tags/$tag/$namewithoutthistag\"\n");
                            if (!$testmode &&
                                !link("$archivefolder/$entry", "$tagsfolder/$tag/$namewithoutthistag"))
                                trace(LOG_ERR, "ERROR linking \"$entry\" to \"tags/$tag/$namewithoutthistag\"\n");
                        }
                    }
                }
            }
            closedir($handle);
        }
        if (!$testmode) cleanUpTagFolder($unusedFiles, $tagsfolder);
    }
    unlink($inboxfolder . "/.FbmDMS_is_active");
    trace(LOG_DEBUG, "Scanning finished!\n");

    
    
    function findPdfTags($textarr, &$tagsarr) {
        global $tagrules;
        
        foreach ($tagrules as $tag => $rule) {
            $ORarr = explode(',', $rule);
            foreach ($ORarr as $search) {
                $ANDarr = explode('&', $search);
                if (matchAll($ANDarr, $textarr)) {
                    array_push($tagsarr, $tag);
                    continue 2;
                }
            }            
        }
    }
    
    function findPdfSubject($textarr, $default = "") {
        global $renamerules;
        foreach ($renamerules as $rule => $name) {
            $ORarr = explode(',', $rule);
            foreach ($ORarr as $search) {
                $ANDarr = explode('&', $search);
                if (matchAll($ANDarr, $textarr)) {
                    return $name;
                }
            }            
        }
        return $default;
    }
    
    function matchAll($searcharr, $linearr) {
        foreach ($searcharr as $search) {
            if (!matchInLines($search, $linearr))
                return false;
        }
        return true;
    }
    
    function matchInLines($search, $linearr) {
        foreach ($linearr as $line) {
            if (fnmatch("*$search*", $line, FNM_CASEFOLD)) {
                trace(LOG_DEBUG, "!$search matches '$line'\n");
                return true;
            }
        }
        trace(LOG_DEBUG, "!!$search did not match\n");
        return false;
    }
    
    function findPdfDate($textarr, $filename) {
        global $now, $dateseperator;
        // default to file creation time
        // linux: access (last read) / modify (last content modification) / change (last meta data change)
        $namedate = date('Y' . $dateseperator . 'm' . $dateseperator . 'd', filemtime($filename));
        foreach ($textarr as $line) {
            unset($matches);
            if (preg_match("/(31|30|[012]\d|\d)[-.\/](0\d|1[012]|\d)[-.\/](20[0-9][0-9])/", $line, $matches)) { // dd.mm.20yy
                $namedate = join($dateseperator, array($matches[3], $matches[2], $matches[1]));
                break;
            }
            unset($matches);
            if (preg_match("/(0\d|1[012]|\d)[-.\/](31|30|[012]\d|\d)[-.\/](20[0-9][0-9])/", $line, $matches)) { // mm.dd.20yy
                $namedate = join($dateseperator, array($matches[3], $matches[1], $matches[2]));
                break;
            }
            unset($matches);
            if (preg_match("/(20[0-9][0-9])[-.\/](31|30|[012]\d|\d)[-.\/](0\d|1[012]|\d)/", $line, $matches)) { // 20yy.mm.dd
                $namedate = join($dateseperator, array($matches[1], $matches[2], $matches[3]));
                break;
            }
        }
        return $namedate;
    }
    
    function recyclefile($basepath, $file) {
        global $recyclebin;
        
        if (strlen($file) > strlen($basepath) &&
            0 == strncmp($basepath, $file, strlen($basepath)))
        {
            $file = substr($file, strlen($basepath)+1);
            trace(LOG_DEBUG, "recyclefile: removed basepath '$basepath' from file '$file'\n");
        }
                
        if (!empty($recyclebin)) {
            if (!is_dir(dirname("$recyclebin/$file")))
                mkdir(dirname("$recyclebin/$file"), 0777, true);
            if (is_dir($recyclebin))
                rename("$basepath/$file", getNextFreeFilename("$recyclebin/$file"));
        } else {
            unlink("$basepath/$file");
        }
    }
    
    function getNextFreeFilename($filepath) {
        $out = $filepath;
        $file_parts = pathinfo($filepath);
        
        $a = $file_parts['dirname'] . "/" . $file_parts['filename'];
        $b = $file_parts['extension'];
        $i = 0;
        
        if (!empty($b))
        {
            while (file_exists($out)) {
                $out = $a . " " . ++$i . "." . $b;
            }
        } else {
            while (file_exists($out)) {
                $out = "$filepath." . ++$i;
            }
        }
        return $out;
    }
    
    function getOCRfilename($pdf) {
        global $OCRPrefix;
        
        $pdf_parts = pathinfo($pdf);
        return getNextFreeFilename($pdf_parts['dirname'] . "/$OCRPrefix" . trim($pdf_parts['filename']) .'.'. $pdf_parts['extension']);
    }
    
    // @returns count of tags found or FALSE on error
    function gettags ($str, &$tags) {
        $ret = preg_match_all("/#([^\.#\s]+)/", $str, $matches);
        $tags = $matches[1];
        return $ret;
    }
    
    function gethashtags ($str, &$tags) {
        $ret = preg_match_all("/(#[^\.#\s]+)/", $str, $matches);
        $tags = $matches[1];
        return $ret;
    }
    
    function stripDateAndTags($str) {
        $str = preg_replace("/\d\d\d\d-[0-1]\d-[0-3]\d\s*/", "", $str);
        $str = preg_replace("/\s*#[^\.#\s]+/", "", $str);
        return $str;
    }
    
    // $level should be one of LOG_DEBUG, LOG_INFO, LOG_ERR
    function trace($level, $message) {
        global $logfile, $loglevel, $timezone;
        
        if ($loglevel < $level) return;
        
        if ($logfile == "syslog") {
            syslog($level, $message);
        } else {
            $now = new DateTime();
            $now->setTimezone(new DateTimeZone($timezone));
            $message = $now->format('Y-m-d H:i:s') . " " . $message;
            if ($logfile == "stdout")
                echo $message;
            else
                file_put_contents($logfile, $message, FILE_APPEND);
        }
    }
    
    function listAllFiles($path) {
        $result = array(); 
        if (file_exists($path)) {
            $all = scandir($path);
            foreach ($all as $one) {
                if ($one == "." || $one == "..") continue;
                if (is_dir($path . '/' . $one))
                    $result = array_merge($result, listAllFiles($path . '/' . $one));
                else
                    $result[] = $path . '/' . $one;
            }
        }
        return $result;
    }
    
    function cleanUpTagFolder($unusedFiles, $tagspath) {
        foreach ($unusedFiles as $file) {
            trace(LOG_INFO, "Deleting $file\n");
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
