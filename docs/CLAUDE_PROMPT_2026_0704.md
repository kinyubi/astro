# Database Rework

## Projects Table DDL Changes
- Add the WantBetter, Notes, SocialBlurb  fields to the Projects table and migrate the values from the Objects table. 
- Delete the MosaicConfig, Status, TotalGoodLights, TotalIntegrationMins and CreatedDate fields from the Projects table. These fields are not used and will never be needed.

## Objects Table DDL Changes
- After migrating objects from the Objects table to the Projects table, delete ProjectFolder, WantBetter, Notes, SocialBlurb, LastObservationField and IsMosaic fields from Objects table. The Objects table fields should all pertain to the DSO, and not the project or observation.

## Observation Table Changes
For the Observations table, we need to add the ObservationFolder field. There should be a 1-to-1 correspondence between observation folders and observation table entries. An observation folder is in the project folder and begins with a datecode YYYYMMDD. The following Observation table fields should be deleted: RejectedLights and BortleScale.

## New Landing Page for Admin
We want the ability to enter observations from the Admin app. That dictates a new landing page that will let the user opt between DSO/Project Maintenance (current scope of admin) and Observation Management.

## Observation Folder Name Pattern
The two possible patterns for the observation folder name are:

-  *^(/d{8})_(/d+)x(\d+)s_([A-Z0-9]+)$*   - where match1 is ObservationDate in YYYYMMDD format; match2 is TotalExposures; match3 is ExposureTimeSecs; match4 is EquipmentID
- *^(/d{8})_([A-Z0-9]+)$*   - where match1 is ObservationDate in YYYYMMDD format; match2 is EquipmentID

## Lights Folder Name Pattern
Lights folder name pattern is *^lights_([A-Z0-9]+)$* where match1 is EquipmentID. A lights folder contains all subexposure FIT files from all observations. 

## FIT Filename Pattern
The FIT filename pattern is *^[A-Z0-9_]+_(\d{2})\.0s_[A-Z0-9_]+(\d{8})-(\d{6})\.fit$* where match1 is ExposureTimeSeconds, match2 is the date (YYYYMMDD) and match3 is the time (hhmmss) for a subexposure. If the observation folder name does not include exposure information, To calculate TotalExposures you could count the number of files where date matches ObservationDate and hh is greater than 12 or where date matches (ObservationDate + 1day) and hh is less than 12.  

## Observation Management
In Observation Management you can add a new observation or edit an existing one. There should be an AI Generate button when adding or editing a new observation. AI Generate should only be available if running locally.

If the ObservationFolder is specfied, it will auto-populate these fields as specified:

- **ObservationDate** - Calculate from first 8 characters of ObservationFolder
- **StartTime** - Earliest FIT file in lights folder where date matches ObservationDate and hh is greater than 12 or where date matches (ObservationDate + 1day) and hh is less than 12.
- **EndTime** - Latest FIT file in lights folder where date matches ObservationDate and hh is greater than 12 or where date matches (ObservationDate + 1day) and hh is less than 12.
- **ExposureTimeSeconds** - taken from match in observation folder name or match in lights folder FIT file name that is between *StartTime* and *EndTime*.
- **TotalExposures** - taken from match in observation folder name or count of the number of FIT files that are between *StartTime* and *EndTime* inclusive.
- **GoodLights** - If TotalExposures value came from ObservationFolder name, then the number of FIT files that are between *StartTime* and *EndTime* inclusive. If matching FIT files is 0, do not populate field.
- **Temperature** - Use a weather API that gets the temperature for Star, Idaho at the time midway between StartTime and EndTime.
- **Humidity** - Use a weather API that gets the humidity for Star, Idaho at the time midway between StartTime and EndTime.
