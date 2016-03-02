<?
    /* ------------------------------------------------------ */
    /* ----------------- Configuration Area ----------------- */
    /* ------------------------------------------------------ */
    
    // Set $scanfolder to the folder which contains your documents.
    // Without trailing (back)slash!
    $scanfolder = dirname(__FILE__);
    
    // In $tagsfolder your tags will be created. Please use a fresh folder.
    // Everything here is subject to be deleted! Without trailing (back)slash!
    $tagsfolder = $scanfolder . "/tags";
    
    // $logfile is the path to a logfile OR "syslog" OR "stdout"
    $logfile = dirname(__FILE__) . "/FileBasedMiniDMS.log";
    
    // $loglevel can be 0 (none), 3 (error), 6 (info), 7 (all)
    $loglevel = 3;
    
    // $timezone. just for logging purposes.
    $timezone = 'Europe/Berlin';
    
?>