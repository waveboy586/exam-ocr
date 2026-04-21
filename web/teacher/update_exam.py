import json
import mysql.connector
import sys
import os

# การตั้งค่า Database (ต้องตรงกับ config.php — อ่านจาก env)
DB_CONFIG = {
    "host":     os.getenv("DB_HOST",     "localhost"),
    "user":     os.getenv("DB_USER",     "root"),
    "password": os.getenv("DB_PASSWORD", ""),
    "database": os.getenv("DB_NAME",     "exam_ocr"),
    "charset":  os.getenv("DB_CHARSET",  "utf8mb4"),
}

def update_exam_in_mysql(json_path):
    if not os.path.exists(json_path):
        print(json.dumps({"status": "error", "message": f"File not found: {json_path}"}))
        return

    try:
        with open(json_path, "r", encoding="utf-8") as f:
            exam_data = json.load(f)
    except Exception as e:
        print(json.dumps({"status": "error", "message": f"Invalid JSON: {str(e)}"}))
        return

    exam_id = exam_data.get("exam_id")
    if not exam_id:
        print(json.dumps({"status": "error", "message": "Missing exam_id for update"}))
        return

    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor()

        # 1. Update Exam Metadata (หัวข้อและคำชี้แจง)
        exam_title = exam_data.get("exam_title", "Untitled Exam")
        instr = exam_data.get("instructions", "")
        
        cursor.execute(
            "UPDATE exams SET title = %s, instructions_content = %s WHERE id = %s",
            (exam_title, instr, exam_id)
        )

        # 2. DELETE OLD CONTENT (ลบไส้ในเก่าทิ้งก่อนลงใหม่)
        # ต้องลบย้อนศรเพื่อป้องกัน Foreign Key Error: Choices -> Questions -> Sections
        
        # 2.1 หา question_id ทั้งหมดของ exam นี้เพื่อลบ choices/sub_questions
        cursor.execute("SELECT id FROM questions WHERE exam_id = %s", (exam_id,))
        q_ids = [row[0] for row in cursor.fetchall()]
        
        if q_ids:
            # แปลง list เป็น string ขั้นด้วย comma สำหรับ SQL IN (...)
            q_ids_str = ', '.join(map(str, q_ids))
            cursor.execute(f"DELETE FROM choices WHERE question_id IN ({q_ids_str})")
            cursor.execute(f"DELETE FROM sub_questions WHERE question_id IN ({q_ids_str})")
            
        # 2.2 ลบ Questions และ Sections
        cursor.execute("DELETE FROM questions WHERE exam_id = %s", (exam_id,))
        cursor.execute("DELETE FROM exam_sections WHERE exam_id = %s", (exam_id,))

        # 3. INSERT NEW CONTENT (วนลูปเพิ่มข้อมูลใหม่ลงใน ID เดิม)
        sections = exam_data.get("sections", [])
        total_questions = 0

        for sec in sections:
            order = sec.get("section_order", 1)
            title = sec.get("section_title", "")
            sec_type = "mixed" 
            
            cursor.execute(
                "INSERT INTO exam_sections (exam_id, section_order, section_title, section_type) VALUES (%s, %s, %s, %s)",
                (exam_id, order, title, sec_type)
            )
            section_id = cursor.lastrowid

            questions = sec.get("questions", [])
            for q in questions:
                number = q.get("number")
                q_text = q.get("question")
                q_type = q.get("type", "short_answer")
                note = q.get("description", "")
                essay_ans = q.get("essay_answer", "")
                q_score = q.get("score")
                try:
                    q_score = float(q_score)
                except:
                    q_score = 1
                if str(q_type).lower() in ["instruction", "header", "info"]:
                    q_score = 0
                    
                cursor.execute(
                    "INSERT INTO questions(exam_id, section_id, number, question, type, answer, note, score)VALUES (%s, %s, %s, %s, %s, %s, %s, %s)",
                    (exam_id, section_id, number, q_text, q_type, essay_ans, note, q_score)
                )
                question_id = cursor.lastrowid
                total_questions += 1

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
                        if sq_text or sa_text:
                            cursor.execute(
                                "INSERT INTO sub_questions (question_id, sub_question, sub_answer) VALUES (%s, %s, %s)",
                                (question_id, sq_text, sa_text)
                            )

        conn.commit()
        cursor.close()
        conn.close()
        
        # ส่ง JSON กลับไปบอก PHP ว่าสำเร็จ
        print(json.dumps({"status": "success", "exam_id": exam_id, "questions_count": total_questions, "action": "update"}))

    except mysql.connector.Error as err:
        print(json.dumps({"status": "error", "message": f"Database Error: {err}"}))
    except Exception as e:
        print(json.dumps({"status": "error", "message": f"Error: {str(e)}"}))

if __name__ == "__main__":
    if len(sys.argv) > 1:
        json_file_path = sys.argv[1]
        update_exam_in_mysql(json_file_path)
    else:
        print(json.dumps({"status": "error", "message": "No input file provided"}))