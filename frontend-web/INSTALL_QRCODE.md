# Install QRCode Library

Run this command to install the QR code library:

```bash
cd frontend-web
npm install qrcode.react
npm install --save-dev @types/qrcode.react
```

Alternative (if qrcode.react has issues):
```bash
npm install qrcode
npm install --save-dev @types/qrcode
```

## Usage

### With qrcode.react (Recommended for React):
```tsx
import QRCode from 'qrcode.react';

<QRCode
  value="QR_PAYLOAD_HERE"
  size={256}
  level="H"
  includeMargin={true}
/>
```

### With qrcode (Alternative):
```tsx
import QRCode from 'qrcode';
import { useEffect, useRef } from 'react';

const canvas = useRef<HTMLCanvasElement>(null);

useEffect(() => {
  if (canvas.current) {
    QRCode.toCanvas(canvas.current, 'QR_PAYLOAD_HERE', {
      width: 256,
      margin: 2,
      errorCorrectionLevel: 'H'
    });
  }
}, []);

<canvas ref={canvas} />
```
