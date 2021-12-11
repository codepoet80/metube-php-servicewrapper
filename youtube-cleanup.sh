#!/bin/bash
find /home/pi/youtube-dl/*.mp4 -amin +30 -exec rm -f {} \;

