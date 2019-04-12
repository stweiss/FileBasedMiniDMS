# FileBasedMiniDMS

FileBasedMiniDMS.php    by Stefan Weiss (2017-2019)  
*Version 0.14 12.04.2019*  
https://github.com/stweiss/FileBasedMiniDMS  

### CHANGELOG
Version 0.14 (12.04.2019)
- don't ORC files, which already have been ocr'ed. Should have been happening only in special rare cases. (issue #9)
- change to long php opening tags for better php compatibility (issue #6)

Version 0.13 (22.10.2018)
- improved detection of dates (thanks vanto) (pull #7)

Version 0.12b (12.06.2017)
- New: $dateseperator can be modified in config.php 
- Change: Default date for rename is now creation date of the pdf. (was "now" before)

Version 0.11 (08.06.2017)
- New: automatic OCR and automatic rename
        
Version 0.02 (02.03.2016)
- release of this file based document management system.
- sorts files with hashtags into hashtag-folders.
        
### INSTALL
1. Place this file on your FileServer/NAS
2. For OCR (Step 1): Install Docker and pull an ocrmypdf image, eg. ```docker pull jbarlow83/ocrmypdf```
3. For Automatic rename (Step 1.1): make sure that **pdftotext** is available.
3. Adjust settings for this script in *config.php* to fit your needs
3. Create a cronjob on your FileServer/NAS to execute this script regularly. (In DSM you can do this in *Control Panel* -> *Task Scheduler*) It might be required to assign root privilege.  
   ex. ```php /volume1/home/stefan/Scans/FileBasedMiniDMS.php```  
   or redirect stdout to see PHP Warnings/Errors:  
       ```php /volume1/home/stefan/Scans/FileBasedMiniDMS.php >> /volume1/home/stefan/Scans/my.log 2>&1```  

### NOTES
This script works in three steps. Each step can be turned on/off in config.php:

#### Step 1: OCR
OCR pdf files in the $inboxfolder, whose filename matches $matchWithoutOCR

#### Step 1.1: Rename ocr'ed files based on keywords and date
The pdf is going to be renamed to following structure: "\<date\> \<name\> \<tags\>.pdf"

*\<date\>*: The script tries to find a date in the pdf. If none is found the current date is used.  
*\<name\>*: You can define *$renamerules*. The first rule which matches the ocr'ed content of the first page is used. You can use the operators **&** (AND) and **,** (OR) and you can use the wildcard operators **?** and **\***.  
*\<tags\>*: In *$tagrules* you can specify your tags. All matching rules will add their tag to the filename. You can use the same operators here.  

#### Step 2: Tagging
This script creates a subfolder for each hashtag it finds in your filenames
and creates a hardlink in that folder.
Documents are expected to be stored flat in one folder. Name-structure needs
to be like "\<any name\> #hashtag1 #hashtag2.extension".

eg: "Documents/Scans/2015-12-25 Bill of Santa Clause #bills #2015.pdf"
will be linked into:  
+ "Documents/Scans/tags/2015/2015-12-25 Bill of Santa Clause #bills.pdf"
+ "Documents/Scans/tags/bills/2015-12-25 Bill of Santa Clause #2015.pdf"



### FAQ
**Q:** How do I assign another tag to my file?  
**A:** Simply rename the file in the $scanfolder and add the tag at the end (but
   before the extension).

**Q:** How can I fix a typo in a documents filename?  
**A:** Simply rename the file in the $scanfolder. The tags are created from scratch
   at the next scheduled interval and the old links and tags are automatically
   getting removed.


### Disclaimer
Make sure to have a backup before you start using this script. You use this software on your own risk.
