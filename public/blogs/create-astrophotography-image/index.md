---
title: "From Photons to Picture: How I Create an Astrophotography Image"
date: 2026-06-19
tags: [astrophotography, workflow, PixInsight, process, Seestar]
summary: "How do I create an astrophotography picture? There's a lot more involved than you might imagine."
thumbnail: ""
---

This is where we're headed.

![Baseline image](/blogs/create-astrophotography-image/images/combined_web.jpg "The Bat Nebula — the finished result of a night's work and hours of processing")

That's the Bat Nebula, captured from my backyard. What you're looking at isn't a single photograph — it's the result of hundreds of short exposures, stacked together, and then carefully processed to pull the object out of the noise. Here's how it gets made.

## My Setup

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

I use an equatorial mount that attaches between the tripod and telescope. This gives the telescope better tracking accuracy as it follows objects across the sky.

![Equatorial mount](/blogs/create-astrophotography-image/images/eq_mount.jpg "Equatorial mount, which attaches between the tripod and telescope for better tracking accuracy")

I also have 3D-printed dew shields for each telescope to keep moisture from forming on the lens and to block ambient light from the sides.

![Dew shield](/blogs/create-astrophotography-image/images/dew_shield.webp "3D-printed dew shield, attached to block dew and ambient light")

And because a long night of imaging will drain a battery in a hurry, I plug a power bank into each telescope's USB port to keep things running until morning.

## Determine One or More Targets to Observe

Decide which object to observe. I built a DSO (Deep Sky Object) Visibility Report that tells me what objects are viable for a given night — the window when each object is high enough to photograph well, its angular size, its type, brightness, and constellation. Pressing an object's information button pulls up extended details that help me plan.

![Visibility report](/blogs/create-astrophotography-image/images/visibility_report.jpg "My DSO Visibility Report")

The telescope lets me create an observation plan using an app on my iPhone or iPad. I tell it which object or objects I want to capture and the start and end time for each one. Once the telescope is up and running, it handles the rest — find, focus, photograph.

One other thing to decide before you go out: exposure time. The telescope can take continuous 10-, 20-, 30-, or 60-second subexposures. You set it once in the menu and let it run.

## Ready the Telescope

Turn on the smart telescope and open its arm.

Because I use an equatorial mount, I run the telescope's polar alignment function first. Polar alignment lines up the mount's rotational axis with Earth's — pointed at the celestial pole, near Polaris in the northern hemisphere — so it can accurately track the sky as the Earth rotates underneath it. Skip this step and stars start to trail or smear during longer exposures. It only takes a few minutes, but it makes a real difference.

![Polar alignment](/blogs/create-astrophotography-image/images/polar_alignment.jpg "Performing polar alignment before a session")

Then I attach the dew shield and plug in the power bank, and we're ready to go.

## Run the Observation

- Verify the desired exposure time is set.
- Run a previously created plan, or select a deep sky object from the app's Sky Atlas and tell it to slew to the target and begin photographing.
- Verify focus. Watch the images as they're taken and progressively stacked in the app. If stars appear bloated, manually run autofocus. Focus can drift with temperature swings — especially on cold nights — so it's worth keeping an eye on early in the session.
- Verify object selection. Make sure what the telescope is photographing actually resembles what's being displayed in the app.
- Once everything checks out, you can leave the telescope unattended until morning, or until the observations are complete.

## Create Master Images

The telescope has a built-in stacking capability, but I generally get better results doing it inside PixInsight, where I can cull out low-quality subexposures before they go into the stack. The images in this post came from a master created that way.

Here's what that process looks like:

  - The subexposures are transferred from the telescope to a computer.
  - I run a PixInsight tool called **Blink** to review every frame. Clouds, satellite trails, airplane streaks, trees wandering into the frame — each one gets marked for removal manually. It's tedious, but skipping it shows up in the final image.
  - Then I run a script called **Weighted Batch Preprocessing**. It does the heavy lifting:
    - **Debayering** — the sensor captures individual red, green, and blue pixels; this step decodes each subexposure and turns it into a proper RGB color image.
    - **Alignment** — the debayered images are registered by matching their stars against a catalog of known star positions.
    - **Quality filtering** — as images are aligned, quality metrics like signal-to-noise ratio and star count are measured. Frames that don't meet the threshold get dropped automatically.
    - **Stacking** — the remaining good frames are combined. Each individual frame is almost invisibly faint, but stacking adds the signal together until the object starts to emerge from the dark.
    - **Drizzling** (optional) — if the image quality is good enough, the program can interpolate between pixels and produce a second, higher-resolution master. When it works, the difference is noticeable.

I compare the resulting master files and pick the one I think is best. That becomes the starting point for everything that follows.

![Master file for the Bat Nebula](/blogs/create-astrophotography-image/images/bat_master.jpg "A master file created for the Bat Nebula")

## Process the Master

I use PixInsight for all of my processing. I have one workflow I use for galaxies and a slightly different one for nebulae. Here's the nebula path, using the Bat Nebula as the example.

**Color Calibration.** The first thing I do is run _Spectrophotometric Color Calibration_. The program figures out where in the sky the image was taken by matching visible stars against an extensive database, then compares their colors against cataloged photometric data and adjusts the color balance so stars and nebulae have physically accurate colors. It's a good foundation to build on.

**Background Extraction.** Next, I remove large-scale gradients from the image — light pollution, moon glow, uneven illumination. The goal is to leave only the real signal sitting on a clean, flat background. There are several tools for this in PixInsight; I pick the one that works best for the frame.

**Image Cropping.** The edges of a stacked image are almost always degraded — fewer frames overlapping out there, more noise. Cropping them off early keeps things like noise reduction and blur reduction from being thrown off by bad data at the margins.

**Blur Reduction.** I run a tool called _BlurXTerminator_, which uses AI-based deconvolution to identify and correct naturally occurring optical distortions in the image. I keep the settings conservative at this stage — just enough to fix the most obvious softness.

**Noise Reduction.** A light pass with _NoiseXTerminator_ smooths out random background noise while doing its best to preserve real detail in the nebula and stars. Also AI-based, and surprisingly good at knowing the difference.

![Crop, SPCC, DBE, Blur and noise reduced](/blogs/create-astrophotography-image/images/crop_spcc_dbe_bxt_nxt.jpg "After crop, color calibration, background extraction, blur reduction, and noise reduction. Still not much to look at.")

It may not look dramatically different from the master at this point. But a close inspection reveals real improvements — and the most visible changes are still coming, once we separate out the stars.

**Remove the Stars.** For nebulae (and sometimes galaxies), I split the image into two versions: a starless image and a stars-only image, using a tool called _StarXTerminator_. The reason is that stars and nebulae respond very differently to processing — what helps one often hurts the other. Working on them separately gives me much more control.

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

**Stretching.** Up to this point, the images have been linear — meaning all the data is there, but to the eye they just look black. Think of it like a film negative: the information is captured, but you can't really see it until it's developed. Stretching is the developing step.

I use a tool called _Generalized Hyperbolic Stretch_ along with _Curves Transformation_ to pull the nebula out of the dark. You typically run these more than once on the starless image to get the stretch right. For the stars-only image, there's a script fittingly called _Star Stretch_ that handles it cleanly.

Stretching is probably the single most important step in processing — and easily the most complicated. I watched more YouTube videos on this than I'd care to admit before I started to get a feel for it.

<div class="img-compare">
  <figure>
    <img src="/blogs/create-astrophotography-image/images/starless_stretched.jpg">
    <figcaption>Bat Nebula—stretched and sharpened starless image</figcaption>
  </figure>
  <figure>
    <img src="/blogs/create-astrophotography-image/images/stars_stretched.jpg">
    <figcaption>Bat Nebula—stretched stars with star reduction
    </figcaption>
  </figure>
</div>

## Post-Processing

Once the image is stretched, I move into post-processing — the final round of adjustments before publishing. Think of it like editing a photo on your phone, except instead of a phone you're using PixInsight or reaching for Photoshop or GIMP. Most of these tools are applied to the starless image:

<ul>
  <li>Brightness/Contrast</li>
  <li>Exposure</li>
  <li>Hue/Saturation</li>
  <li>Levels/Curves</li>
  <li>Sharpening</li>
  <li>Rotating</li>
  <li>Cropping and composition</li>
  <li>Star Reduction</li>
  <li>Local contrast (dodge and burn)</li>
  <li>Palette Application</li>
</ul>

&nbsp;

For the stars-only image, I sharpen the stars and reduce their overall intensity using either a script or a mathematical transform. Then I recombine the two — starless and stars — which gives me a baseline image I can use to create the different formats for publishing.

## Publishing

The final image is at least 1080×1920 pixels. From there, I create versions for different uses: a social media crop at 4:5 aspect ratio, a landscape version for widescreen displays, and a portrait version. If drizzling produced a clean result, I'll also make a 4K wallpaper at 3840×2160. For the Bat Nebula, I rotated the image so the wings of the nebula read naturally — easier to picture as a bat when it's oriented the right way.

<div class="img-compare">
  <figure>
    <img src="/blogs/create-astrophotography-image/images/fav_web.jpg">
    <figcaption>The Bat Nebula—social media format (4:5)</figcaption>
  </figure>
  <figure>
    <img src="/blogs/create-astrophotography-image/images/hoo_fav_web.jpg">
    <figcaption>The same image in an HOO palette for a different look</figcaption>
  </figure>
</div>

![Portrait mode](/blogs/create-astrophotography-image/images/portrait_web.jpg "Portrait mode")

![Landscape mode](/blogs/create-astrophotography-image/images/landscape_web.jpg "Landscape mode")

And that's it — a night outside in the dark, hours at the computer, and one more nebula on the wall. The finished images go into the gallery and slideshow at [astro.wiibopp.com](https://astro.wiibopp.com).
