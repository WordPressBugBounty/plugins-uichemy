## OLD
cd .. && zip -x '*.git*' -x '*.DS_Store*' -vr ~/Desktop/uichemy-wp.zip ./uichemy && cd uichemy

## NEW
zip -vr ~/Desktop/uichemy-temp.zip ./uichemy \
  -x '*.git*' \
  -x '*.DS_Store*' \
  -x 'uichemy/dashboard/*'

zip -ur ~/Desktop/uichemy-temp.zip \
  uichemy/dashboard/assets \
  uichemy/dashboard/build \
  -x '*.git*' \
  -x '*.DS_Store*' \

