from flask import Flask, request, jsonify
import face_recognition
import numpy as np
import cv2
import binascii

app = Flask(__name__)

def process_image(file_stream):
    # Read image from file stream
    file_bytes = np.frombuffer(file_stream.read(), np.uint8)
    image = cv2.imdecode(file_bytes, cv2.IMREAD_COLOR)
    return image

@app.route('/detect-face', methods=['POST'])
def detect_face():
    if 'image' not in request.files:
        return jsonify({'success': False, 'message': 'No image provided'}), 400

    file = request.files['image']
    image = process_image(file)
    rgb_image = cv2.cvtColor(image, cv2.COLOR_BGR2RGB)

    # Detect faces
    face_locations = face_recognition.face_locations(rgb_image)

    if not face_locations:
        return jsonify({'success': False, 'message': 'No face detected'}), 404

    # Get the largest face
    top, right, bottom, left = max(face_locations, key=lambda f: (f[2] - f[0]) * (f[1] - f[3]))

    # Add padding (optional, but good for student ID cards)
    height, width, _ = image.shape
    pad_h = int((bottom - top) * 0.5)
    pad_w = int((right - left) * 0.5)

    new_top = max(0, top - pad_h)
    new_bottom = min(height, bottom + pad_h)
    new_left = max(0, left - pad_w)
    new_right = min(width, right + pad_w)

    cropped = image[new_top:new_bottom, new_left:new_right]
    
    # Encode to JPG
    success, encoded_image = cv2.imencode('.jpg', cropped)
    if not success:
        return jsonify({'success': False, 'message': 'Failed to encode image'}), 500

    hex_image = binascii.hexlify(encoded_image).decode('utf-8')

    return jsonify({
        'success': True,
        'method': 'face_detected',
        'image': hex_image
    })

@app.route('/embed', methods=['POST'])
def get_embedding():
    if 'image' not in request.files:
        return jsonify({'success': False, 'message': 'No image provided'}), 400

    file = request.files['image']
    image = process_image(file)
    rgb_image = cv2.cvtColor(image, cv2.COLOR_BGR2RGB)

    # Detect faces
    face_locations = face_recognition.face_locations(rgb_image)
    
    if not face_locations:
         # If no face detected, maybe the image is already cropped?
         # Try using the whole image as the face location? 
         # Or just fail. The prompt says "After student photo is uploaded and auto-cropped", so it should have a face.
         # But dlib might fail on some crops.
         # Let's try to get embedding from the whole image if detection fails, assuming it's a face crop.
         # Actually, face_encodings can take known_face_locations.
         # If no face detected, return empty or error.
         return jsonify({'success': False, 'message': 'No face detected for embedding'}), 404

    # Use the largest face
    largest_face = max(face_locations, key=lambda f: (f[2] - f[0]) * (f[1] - f[3]))
    
    # Get embedding
    embeddings = face_recognition.face_encodings(rgb_image, [largest_face])

    if not embeddings:
        return jsonify({'success': False, 'message': 'Could not generate embedding'}), 500

    return jsonify({
        'success': True,
        'embedding': embeddings[0].tolist()
    })

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5001)
