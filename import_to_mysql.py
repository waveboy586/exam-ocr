import json
import os
import mysql.connector

DB_CONFIG = {
    "host":     os.getenv("DB_HOST",     "localhost"),
    "user":     os.getenv("DB_USER",     "root"),
    "password": os.getenv("DB_PASSWORD", ""),
    "database": os.getenv("DB_NAME",     "exam_ocr"),
    "charset":  os.getenv("DB_CHARSET",  "utf8mb4"),
}

def load_exam_from_file(path: str):
    with open(path, "r", encoding="utf-8") as f:
        return json.load(f)

def save_to_mysql(exam_data: dict):
    conn = mysql.connector.connect(**DB_CONFIG)
    cursor = conn.cursor()

    # -------------------------
    # 1) ข้อมูลระดับข้อสอบ (exam)
    # -------------------------
    exam_title = exam_data.get("exam_title", "ข้อสอบที่ไม่มีชื่อ")
    sections = exam_data.get("sections", [])

    # ดึงคำชี้แจง ถ้ามี
    instr = exam_data.get("exam_instructions") or {}
    instr_title = instr.get("title")
    instr_content = instr.get("content")

    # insert exam (เก็บทั้ง title + instructions)
    cursor.execute(
        """
        INSERT INTO exams (title, instructions_title, instructions_content)
        VALUES (%s, %s, %s)
        """,
        (exam_title, instr_title, instr_content),
    )
    exam_id = cursor.lastrowid

    # -------------------------
    # 2) SQL template ต่าง ๆ
    # -------------------------
    insert_section_sql = """
        INSERT INTO exam_sections
        (exam_id, section_order, section_title, section_type)
        VALUES (%s, %s, %s, %s)
    """

    insert_question_sql = """
        INSERT INTO questions
        (exam_id, section_id, number, question, type, answer, note)
        VALUES (%s, %s, %s, %s, %s, %s, %s)
    """

    insert_choice_sql = """
        INSERT INTO choices
        (question_id, choice_label, choice_text)
        VALUES (%s, %s, %s)
    """

    labels = "ABCDEFGHIJKLMNOPQRSTUVWXYZ"
    total_questions = 0

    # -------------------------
    # 3) loop ใส่ section + questions
    # -------------------------
    # ให้ section_order รันจาก 1,2,3,... ถ้าใน JSON ไม่ได้ระบุ
    for sec_index, sec in enumerate(sections, start=1):
        order = sec.get("section_order", sec_index)
        title = sec.get("section_title", "")
        sec_type = sec.get("type", "multiple_choice")

        # 2.1 insert section
        cursor.execute(
            insert_section_sql,
            (exam_id, order, title, sec_type),
        )
        section_id = cursor.lastrowid

        # 2.2 insert questions ของ section นี้
        for q in sec.get("questions", []):
            number = q.get("number")
            q_text = q.get("question")
            q_type = q.get("type", sec_type)
            answer = q.get("answer")
            note = q.get("note")  # <-- เก็บ note เข้า column questions.note

            cursor.execute(
                insert_question_sql,
                (exam_id, section_id, number, q_text, q_type, answer, note),
            )
            question_id = cursor.lastrowid
            total_questions += 1

            # 2.3 insert choices (ถ้ามี)
            choices = q.get("choices") or []
            for idx, choice_text in enumerate(choices):
                label = labels[idx] if idx < len(labels) else None
                cursor.execute(
                    insert_choice_sql,
                    (question_id, label, choice_text),
                )

    conn.commit()
    cursor.close()
    conn.close()

    print(f"Saved {total_questions} questions into MySQL (exam_id={exam_id})")

def main():
    data = load_exam_from_file("exam_questions.json")
    save_to_mysql(data)

if __name__ == "__main__":
    main()