import requests
from flask import Flask, request, jsonify
import cv2
import numpy as np
from PIL import Image
import io

app = Flask(__name__)

CASCADE_PATH = cv2.data.haarcascades + 'haarcascade_frontalface_default.xml'
face_cascade = cv2.CascadeClassifier(CASCADE_PATH)

def expand_bbox(x, y, w, h, img_w, img_h, margin=0.2):
    mx = int(w * margin)
    my = int(h * margin)
    x1 = max(0, x - mx)
    y1 = max(0, y - my)
    x2 = min(img_w, x + w + mx)
    y2 = min(img_h, y + h + my)
    return x1, y1, x2, y2

def crop_to_portrait(img, bbox):
    x1, y1, x2, y2 = bbox
    crop = img[y1:y2, x1:x2]
    h, w = crop.shape[:2]
    # Portrait 3:4
    target_ratio = 3/4
    cur_ratio = w / h
    if cur_ratio > target_ratio:
        # Too wide, crop sides
        new_w = int(h * target_ratio)
        offset = (w - new_w) // 2
        crop = crop[:, offset:offset+new_w]
    else:
        # Too tall, crop top/bottom
        new_h = int(w / target_ratio)
        offset = (h - new_h) // 2
        crop = crop[offset:offset+new_h, :]
    return crop

@app.route('/detect-face', methods=['POST'])
def detect_face():
    file = request.files['image']
    img_bytes = file.read()
    img = np.array(Image.open(io.BytesIO(img_bytes)).convert('RGB'))
    img_cv = cv2.cvtColor(img, cv2.COLOR_RGB2BGR)
    h, w = img_cv.shape[:2]
    faces = face_cascade.detectMultiScale(img_cv, scaleFactor=1.1, minNeighbors=5, minSize=(60, 60))
    if len(faces) > 0:
        # Use largest face
        faces = sorted(faces, key=lambda b: b[2]*b[3], reverse=True)
        x, y, fw, fh = faces[0]
        x1, y1, x2, y2 = expand_bbox(x, y, fw, fh, w, h)
        crop = crop_to_portrait(img_cv, (x1, y1, x2, y2))
        method = 'face_detected'
    else:
        # Center crop to portrait
        target_w, target_h = w, int(w * 4/3)
        if target_h > h:
            target_h = h
            target_w = int(h * 3/4)
        x1 = (w - target_w) // 2
        y1 = (h - target_h) // 2
        crop = img_cv[y1:y1+target_h, x1:x1+target_w]
        method = 'fallback_crop'
    # Resize to 400x533
    crop = cv2.resize(crop, (400, 533), interpolation=cv2.INTER_AREA)
    _, buf = cv2.imencode('.jpg', crop, [int(cv2.IMWRITE_JPEG_QUALITY), 80])
    return jsonify({
        'success': True,
        'method': method,
        'image': buf.tobytes().hex()
    })

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5001)
