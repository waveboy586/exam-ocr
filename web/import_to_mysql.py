import json
import mysql.connector
import sys
import os

# การตั้งค่า Database (อ่านจาก env — ใช้ localhost เป็น fallback สำหรับ XAMPP)
DB_CONFIG = {
    "host":     os.getenv("DB_HOST",     "localhost"),
    "user":     os.getenv("DB_USER",     "root"),
    "password": os.getenv("DB_PASSWORD", ""),
    "database": os.getenv("DB_NAME",     "exam_ocr"),
    "charset":  os.getenv("DB_CHARSET",  "utf8mb4"),
}

def save_to_mysql(json_path):
    if not os.path.exists(json_path):
        print(json.dumps({"status": "error", "message": f"File not found: {json_path}"}))
        return

    try:
        with open(json_path, "r", encoding="utf-8") as f:
            exam_data = json.load(f)
    except Exception as e:
        print(json.dumps({"status": "error", "message": f"Invalid JSON: {str(e)}"}))
        return

    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor()

        # 1. Insert Exam Metadata
        exam_title = exam_data.get("exam_title", "Untitled Exam")
        instr = exam_data.get("instructions", "")
        
        # [เพิ่ม] รับค่า created_by จาก JSON
        user_id = exam_data.get("created_by", None) 
        
        # [แก้] เพิ่ม created_by ลงใน SQL
        cursor.execute(
            "INSERT INTO exams (title, instructions_content, created_by) VALUES (%s, %s, %s)",
            (exam_title, instr, user_id)
        )
        exam_id = cursor.lastrowid

        # 2. Loop Sections
        sections = exam_data.get("sections", [])
        total_questions = 0

        # ค่าที่ยอมรับได้ของ column section_type (ENUM)
        VALID_SECTION_TYPES = {"multiple_choice", "short_answer", "subjective", "subjective_subparts"}

        for sec in sections:
            order = sec.get("section_order", 1)
            title = sec.get("section_title", "")
            # เดา type จากคำถามใน section (ถ้า JSON ไม่ได้ระบุ)
            sec_type = sec.get("type") or sec.get("section_type")
            if not sec_type or sec_type not in VALID_SECTION_TYPES:
                # ดูคำถามแรกเพื่อเดา type
                first_q_type = (sec.get("questions") or [{}])[0].get("type", "")
                if first_q_type in VALID_SECTION_TYPES:
                    sec_type = first_q_type
                else:
                    sec_type = "multiple_choice"  # default ที่ปลอดภัย

            cursor.execute(
                "INSERT INTO exam_sections (exam_id, section_order, section_title, section_type) VALUES (%s, %s, %s, %s)",
                (exam_id, order, title, sec_type)
            )
            section_id = cursor.lastrowid

            # 3. Loop Questions
            questions = sec.get("questions", [])
            for q in questions:
                number = q.get("number")
                q_text = q.get("question")
                q_type = q.get("type", "short_answer")
                note = q.get("description", "") # ใช้ description เป็น note หรือคำอธิบายเพิ่มเติม
                score = q.get("score")  # รับคะแนนจาก JSON
                # essay_answer สำหรับข้อเขียน
                essay_ans = q.get("essay_answer", "")
                try:
                    q_score = float(score)
                except (TypeError, ValueError):
                    q_score = 1
                if str(q_type).lower() in ["instruction", "header", "info"]:
                    q_score = 0
                # Insert Question Main Data
                cursor.execute(
                    "INSERT INTO questions(exam_id, section_id, number, question, type, answer, note, score)VALUES (%s, %s, %s, %s, %s, %s, %s, %s)",
                    (exam_id, section_id, number, q_text, q_type, essay_ans, note, q_score)
                )
                question_id = cursor.lastrowid
                total_questions += 1

                # 4. Handle Sub-Data based on Type
                if q_type == "multiple_choice":
                    choices = q.get("choices", [])
                    labels = "ABCDEFGHIJKLMNOPQRSTUVWXYZ"
                    for idx, c in enumerate(choices):
                        c_text = c.get("text", "")
                        is_correct = 1 if c.get("correct") else 0
                        label = labels[idx] if idx < len(labels) else None
                        
                        cursor.execute(
                            "INSERT INTO choices (question_id, choice_label, choice_text, is_correct) VALUES (%s, %s, %s, %s)",
                            (question_id, label, c_text, is_correct)
                        )

                elif q_type == "short_answer":
                    sub_qs = q.get("sub_questions", [])
                    for sub in sub_qs:
                        sq_text = sub.get("question", "")
                        sa_text = sub.get("answer", "")
                        if sq_text or sa_text: # บันทึกถ้ามีข้อมูลอย่างใดอย่างหนึ่ง
                            cursor.execute(
                                "INSERT INTO sub_questions (question_id, sub_question, sub_answer) VALUES (%s, %s, %s)",
                                (question_id, sq_text, sa_text)
                            )

        conn.commit()
        cursor.close()
        conn.close()
        
        # Output success JSON for PHP to read
        print(json.dumps({"status": "success", "exam_id": exam_id, "questions_count": total_questions}))

    except mysql.connector.Error as err:
        print(json.dumps({"status": "error", "message": f"Database Error: {err}"}))
    except Exception as e:
        print(json.dumps({"status": "error", "message": f"Error: {str(e)}"}))

if __name__ == "__main__":
    # รับ path ไฟล์ JSON จาก command line arguments
    if len(sys.argv) > 1:
        json_file_path = sys.argv[1]
        save_to_mysql(json_file_path)
    else:
        print(json.dumps({"status": "error", "message": "No input file provided"}))