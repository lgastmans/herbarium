Import Herbarium Images
=======================
Go to the Google Drive containing the folders:
	Open Firefox > bookmarks bar > AVBG Web files - Google Drive

Download folders and copy from "Downloads" to "/home/luk/Apps/dryherbarium/storage/import/LowRes"
(Use "Extract To", not "Extract" to unzip the folders)

Then copy each folder using the following Terminal command:
	scp -r /home/luk/Apps/dryherbarium/storage/import/LowRes/<foldername> dryherbarium:/home/u508789628/domains/dryherbarium.aurovilleherbarium.org/dryherbarium/storage/import

SSH into the AVBG Hostinger server:
    Terminal > ssh dryherbarium
    
Navigate to folder "domains/dryherbarium.aurovilleherbarium.org/dryherbarium"
Import each folder using (command defined in: app/Console/Commands/ImportHerbariumImages.php):
    
    alias php82='/opt/alt/php82/usr/bin/php'
    
    php82 artisan herbarium:import-images {path}
    
    (example: php82 artisan herbarium:import-images storage/import/APN)

If filenames need to be renamed:

$ find . -type f -name '*F 2878*'
    ./F 2878.jpg

$ mv "F 2878.jpg" "F 02878.jpg"
