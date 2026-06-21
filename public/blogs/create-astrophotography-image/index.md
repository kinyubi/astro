---
title: "Creating an Astrophotography Photograph"
date: 2026-06-19
tags: [astrophotography, workflow, PixInsight, process, Seestar]
summary: "How do I create an astrophotography picture? There's a lot more involved than you might imagine."
thumbnail: ""
---

## Background Information

I use a ZWO Seestar S30 and Seestar S50 smart telescope. Both have 1080×1920 image sensors and a built-in camera, though each scope has a different magnification. Once a telescope is given a target and its coordinates, it finds the target, focuses, and then tracks it for the specified duration.

<div class="img-compare">
  <figure>
    <img src="/blogs/create-astrophotography-image/images/seestar_s30.jpg">
    <figcaption>ZWO Seestar S30</figcaption>
  </figure>
  <figure>
    <img src="/blogs/create-astrophotography-image/images/seestar_s50.jpg">
    <figcaption>ZWO Seestar S50</figcaption>
  </figure>
</div>

I use an equatorial mount that attaches between the tripod and telescope. This gives the telescope better tracking accuracy.

![Equatorial mount](/blogs/create-astrophotography-image/images/eq_mount.jpg "Equatorial mount, which attaches between the tripod and telescope for better tracking accuracy")

I also have 3D-printed dew shields for each telescope, to keep dew from forming on the lens and to block ambient light. I use power banks that plug into each telescope's USB port to extend battery life.

## Determine One or More Targets to Observe

Decide which object to view. I created a DSO (Deep Sky Object) Visibility Report to tell me what objects are viable for a given day — the time span when each object is viewable, its angular size (how large it appears from Earth), its type, brightness, and constellation. Pressing an object's information button pulls up extended details that can be helpful.

![Visibility report](/blogs/create-astrophotography-image/images/visibility_report.jpg "My DSO Visibility Report")

The telescope lets me create an observation plan. Using an app on my iPhone or iPad, I can tell the telescope which object or objects I want to observe and the start and end time of each observation. All I have to do is ready the telescope, and it does the rest — find, focus, and photograph.

Decide whether you want the telescope to take continuous 10-, 20-, 30-, or 60-second exposures. Once the telescope is up and running, you can step through a menu to set the exposure time.

## Ready the Telescope

Turn on the smart telescope and open its arm.

Because I use an equatorial mount, I have to run the telescope's polar alignment function first. Polar alignment lines up the mount's rotational axis with Earth's — pointed at the celestial pole, near Polaris in the northern hemisphere — so the mount can track the sky accurately as the Earth rotates. Skip it, and stars start to trail or smear during longer exposures.

![Polar alignment](/blogs/create-astrophotography-image/images/polar_alignment.jpg "Performing polar alignment before a session")

Then I attach the dew shield and power bank.

![Dew shield](/blogs/create-astrophotography-image/images/dew_shield.webp "3D-printed dew shield, attached to block dew and ambient light")

## Run the Observation

- Verify the desired exposure time is set.
- Run a previously created plan, or select a deep sky object from the app's Sky Atlas and tell it to slew to the object and begin photographing.
- Verify focus. In the app, watch the images as they're taken and progressively stacked. If stars appear bloated, manually run autofocus. Focus settings can drift with temperature swings, particularly in cold weather.
- Verify object selection. Make sure what the telescope is photographing resembles what's being displayed in the app.
- Once everything is up and running, you can leave the telescope unattended until morning, or until the observation(s) are complete.

## Create Master Images

The telescope has the built-in capability to stack subexposures and create a master. We usually have better results stacking inside PixInsight because we cull out low quality subexposures befpre stacking.  The pictures in this post came from a master created by the telescope.

To create masters in PixInsight, I go through the following steps:
- The subexposures on the telescope are transferred to a computer.
- A "Blink" process is run to weed out frames with clouds, satellite trails, airplane trails, or obstructions such as trees and buildings. This is a manual process, where each frame is reviewed and bad frames are marked for removal.
- Then I run a script called Weighted Batch Preprocessing. After setting the options I want, it performs the following tasks:
  - The subexposures are debayered. The image sensor has individual red, green, and blue receptors; each subexposure is decoded and turned into an RGB color image.
  - The debayered images are then aligned by matching their stars against a catalog of known star positions.
  - As the images are aligned, quality metrics — signal-to-noise ratio, number of stars, and so on — are collected for each one. Based on those metrics, I discard images that don't measure up.
  - After alignment, the remaining good images are stacked. Each image is very faint on its own, but stacking combines them into enough signal to actually "see" the object. The stacked result is saved as a master file.
  - One processing option I often turn on is drizzling. If the quality of my images is good enough, the program can interpolate pixels, giving me a second, higher-resolution master.

  I compare my master files and choose the one I deem best. That master becomes the starting point for crafting the final image.

![Master file for the Bat Nebula](/blogs/create-astrophotography-image/images/bat_master.jpg "A master file created for the Bat Nebula")

## Process the Master

I have one processing workflow I use for galaxies and another I use for nebulae. I use an application called PixInsight to process my master images.

**Color Calibration.** The first thing I always do is color calibration, using a process called _Spectrophotometric Color Calibration_. The program determines where in the sky the picture was taken by matching known stars against an extensive database. It compares star colors and object colors in the image against cataloged photometric data, then adjusts color balance so stars, galaxies, and nebulae have more physically accurate colors.

**Background Extraction.** Next, I use background extraction to remove unwanted large-scale gradients from the image — things like light pollution, moon glow, or uneven illumination. The goal is to leave the real signal — nebulae, galaxies, and stars — sitting on a flatter, cleaner background. I have a selection of tools to choose from for this step.

**Image Cropping.** Usually the image is degraded on the edges of the image. Most people will crop the image so that the edges do not interfere things like noise reduction and blur reduction.

**Blur Reduction.** Then I use a process called _BlurXTerminator_ to sharpen the image using AI-based deconvolution — a process that identifies and removes naturally occurring distortions in the image. At this stage I keep the settings fairly low, since I only want to fix the most obvious distortion.

**Noise Reduction.** Next is a light pass of denoising with a tool called _NoiseXTerminator_. It's another AI-based tool, and it smooths random background noise while trying to preserve real detail in nebulae, galaxies, and stars.

![Crop, SPCC, DBE, Blur and noise reduced](/blogs/create-astrophotography-image/images/crop_spcc_dbe_bxt_nxt.jpg "An image after crop, color calibration, background extraction, blur reduction and noise reduction")

It may not look too different from the original master at this point but a very close up inspection reveals some important improvements to the image. The most noticeable changes will occur once we've separated out the stars.

**Remove the Stars.** When processing nebulae — and sometimes galaxies — I create a starless version of the image and a stars-only version. I use a tool called _StarXTerminator_ to do this. (The final processing step will be to recombine the two.)

<div class="img-compare">
  <figure>
    <img src="/blogs/create-astrophotography-image/images/starless.jpg">
    <figcaption>Without the stars</figcaption>
  </figure>
  <figure>
    <img src="/blogs/create-astrophotography-image/images/stars.jpg">
    <figcaption>Stars only</figcaption>
  </figure>
</div>

**Stretching the Stars-Only and Starless Images.** Up to this point, the images have been linear. To do any of the steps above, I've had to temporarily "stretch" the image just to see what it contains. A linear image holds a lot of information, but to the eye it just looks black. To begin stretching the image into something that looks like a normal picture, I use a tool called _Generalized Hyperbolic Stretch_, along with a process called _Curves Transformation_. You'll typically run these steps more than once on the starless image to get it stretched the proper amount. There's a script fittingly called _Star Stretch_ for stretching the stars-only image. Stretching is probably the most important step in processing, and possibly the most complicated — I watched countless YouTube videos trying to learn how to do it properly.

<div class="img-compare">
  <figure>
    <img src="/blogs/create-astrophotography-image/images/starless_stretched.jpg">
    <figcaption>Stretched starless image with a deblur to sharpen</figcaption>
  </figure>
  <figure>
    <img src="/blogs/create-astrophotography-image/images/stars_stretched.jpg">
    <figcaption>Stretched star image</figcaption>
  </figure>
</div>

## Move On to Post-Processing

After an image is stretched, post-processing can begin. This is where I make the final tweaks before publishing the finished photograph. Think of it like using the editing tools on your phone to make a picture better — except in astrophotography, instead of your phone, you're reaching for an image editor like Photoshop or GIMP. These tools are typically used to improve the starless image. Some of the enhancements that are helpful:

- Brightness/Contrast
- Exposure
- Hue/Saturation
- Levels/Curves
- Sharpening
- Cropping and composition
- Local contrast (dodge and burn)

For the stars-only image I typically sharpen the stars with blur reduction and reduce the intensity of the stars using either a script or a mathematical transform. Then I rejoin the starless and stars-only image which leaves me with a picture that I can publish.

## Publishing
The final image is usually 1080W x 1920H. I also like to make a version of the final image that works for social media (4:5 aspect ratio), and a version that works for desktop wallpaper 1920W x 1080H. If drizzling worked for the image, I'll also create a 4K monitor wallpaper image (3840W x 2160H).

As a final step, I add these pictures to my website's slideshow and picture gallery at [astro.wiibopp.com](https://astro.wiibopp.com)
