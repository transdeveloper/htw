
<div style="padding-top:25px;"><hr>
Feedback => <a href="mailto:sabigail@outlook.co.il">sabigail@outlook.co.il</a><br/>
<?php if(!(explode('/', $_SERVER['REQUEST_URI'])[1] == "add_task")){ ?>
<a href='/add_task' style='font-weight: 900;'>Add Task</a>
<?php } else { ?>
<a href='/' style='font-weight: 900;'>Back</a>
<?php } ?>
</div>