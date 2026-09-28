## Purpose of the file
- This md record, describe and explain the functions / new functions that want to be implemented by the user
- The user will write down and list any new function after 28/9/26
- Agent should read and develop the system accordingly

## Existing or old system work flow of the admin before this entire system:
1. The current existing system, the admin will have to contact the each related personnel manually such as fee receipt from parent, teacher (not sure) for class, rather than a single platform.
2. The records of transaction will then save by the admin manually after received the receipt from the parent, and input to the existing software.

## business rule
1. The teacher will take commision from each lesson taught to the student
2. teacher is not a stable staff of the institute, but rather a free-lance that can take any lesson when available
3. The price of the lesson is rather inconsistent whereas it always flutuates based on student's level, time, and how many section.
    - so it is better to let the admin to decide the fee, rather a system auto-calculation
4. Working hour weekday 9am-10pm, Saturday 9am.6:30pm, closed on Sunday
5. A lesson can have multiple students, but typically a teacher only

## Function
1. Account management for Transaction
- USER ROLE: 
    - ADMIN
    - TEACHER
    - STUDENT   
- The existing account management already include basic account management such as admin, teacher and classroom and so on.
- With the business rules require by the users, 1, 2, 3 and workflow 1.,2.:
    1. I want to integrate the system to track transaction:
        1. which student haven't pay their fee
        2. Record commmision that have to pay to teacher
- More details:
    1. From admin pov:
        - A single page (==transaction page== for now) that include all transaction related info; A table of lessons that include info about student, teacher, schedule time, completion (class success, cancelled, future in-coming), fee amount, teacher commision, payment status, payment proof (ex: img upload by parent), commision status, commision proof (ex: img upload by teacher). The table shoul include sorting based on various condition.
        - the row in transaction page should normally included all the lesson whether it is in-coming, now, or passed based on the timetable as planned.
        - Control all teacher commision rate
        - Control lesson fee
    2. From teacher pov:
        - Similar to admin's transaction page, but only view row that related to him
    3. Student pov:
        - should only view the lesson fee, and upload transaction proof

2. Schedule classroom/lesson
- USER ROLE: 
    - ADMIN
    - TEACHER
    - STUDENT
- This should be a utility function that only accessible for admin and teacher(maybe?)
- This should helps admin to determine which slots will be available to fit the students, instead of the students directly request for the slot
- because of the business rule 2., maybe it would be better to let the classroom atually by lesson instead of a consistent classroom of same students.
- the following will be the workflow:
    1. Students request admin for a lesson (let assume 1 time per week)
    2. Admin will look for the timetable
        1. Let the each slot to be 30 minutes
        2. Timetable will have record which slots already taken by which student and teacher, but it should still let the admin to select the slot, if there want to fit the students in.
        3. Admin could create a lesson by clicking consequtive slots that then later fill in the student(student may not have an account, so let admin create the account for them before use) and teacher
        4. After creating lesson, admin fill in the students and choose teacher from list
            - business rule 5.
            - teacher personal account might need to save info about when they are free 
            - Priorities teacher that are free, but still shows teacher that are not free and maybe clash
        5. After all set, the admin can save the time table.
            - prompt if lesson with no-student, confirm then remove
            - 
    - the timetable page should be roughly like and include:
        - top: header:
        - midle top: the schedule base on a month, the config table that let user choose between week 1,2,3,4
        - middle: the time table base on a day
            - normally view only, have a create a new lesson/clashroom button
            - maybe red colour for student that have fill in the teacher.
            - may delete the classroom there have not student at all (prompt admin before save)
        - at right maybe(maybe appear only when choosing teacher): the list of available teacher (shaded for teacher that is not available for the slot or clash)
        - bottom before footer: 
            - the details of the lesson, students, teacher, time (may fill in by the admin when not completed), fee, commision
            - Admin choose whether only week 1,2,3, or 4 that this lesson reoccurance
    - Teacher pov:
        - timetable show slot with information that only relavant to him
        - currently, let admin decide which slot, instead of teacher choosing
    - Student pov:
        - should be same as the teacher pov
