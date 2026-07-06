# Automated Observation Data Collection
Let me tell you some ways we can automate data collection for the database. After I have done an observation the following information can be ascertained if we are running local and we know the path of the observation data. For example, the last 2 segments of the path C:\Astronomy\myWorks\ngc6995_bat_nebula\20260613_854x30s_S30 are the project directory and observation directory.

## Project Directory
The project directory gives us DSO key so we can identify the relevant record in the Projects table

## Observation Directory
The observation directory gives us the ObservationDate, TotalExposures, ExposureTimeSeconds and Equipment with the pattern "^(\d{8})_(\d+)x(\d{2})s_(.+)$"
