#!/bin/bash
youtube-dl $1 -f '$4[ext=mp4]+bestaudio[ext=aac]/best[ext=mp4]/best' --output "/tmp/$3.tmp"
if [ "$5" == "convert" ]
then
	ffmpeg -i /tmp/$3.tmp -threads 8 -c:v libx264 -preset superfast -crf 20 -profile:v baseline -movflags +faststart /tmp/$3.mp4 >> /tmp/metube.log
	rm /tmp/$3.tmp
	mv /tmp/$3.mp4 $2
else
	mv /tmp/$3.tmp $2$3.mp4
fi
