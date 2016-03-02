# FileBasedMiniDMS

FileBasedMiniDMS.php    by Stefan Weiss (2016)  
*Version 0.02  02.03.2016*

### INSTALL
1. Place this file on your FileServer/NAS
2. Adjust settings for this script in *config.php* to fit your needs
3. Create a cronjob on your FileServer/NAS to execute this script regularly.  
   ex. **php /volume1/home/stefan/Scans/FileBasedMiniDMS.php**  
   or redirect stdout to see PHP Warnings/Errors:  
        **php /volume1/home/stefan/Scans/FileBasedMiniDMS.php > /volume1/home/stefan/Scans/my.log 2>&1**

### NOTES
This script creates a subfolder for each hashtag it finds in your filenames
and creates a hardlink in that folder.
Documents are expected to be stored flat in one folder. Name-structure needs
to be like *"\<any name\> #hashtag1 #hashtag2.extension"*.

eg: *"Documents/Scans/2015-12-25 Bill of Santa Clause #bills #2015.pdf"*  
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
