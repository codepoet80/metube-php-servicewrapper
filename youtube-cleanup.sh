#!/bin/bash
find /home/pi/youtube-dl/*.mp4 -amin +10 -exec rm -f {} \;

