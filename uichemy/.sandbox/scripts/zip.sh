set -ex

cd ..

zip \
    -x '*.git*' \
    -x '*.DS_Store*' \
    -x 'uichemy/scripts/*' \
    -x 'uichemy/dashboard/*' \
    -vr ~/Desktop/uichemy-wp.zip \
    ./uichemy

zip -ur \
    ~/Desktop/uichemy-wp.zip \
    uichemy/dashboard/assets \
    uichemy/dashboard/build

cd uichemy
