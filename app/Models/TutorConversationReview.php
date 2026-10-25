<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TutorConversationReview extends Model
{
    protected $fillable = ['tutor_session_id','requested_by','last_message_id','message_count','is_partial','summary','findings'];
    protected $casts = ['findings' => 'array','is_partial' => 'boolean','last_message_id' => 'integer'];
}
