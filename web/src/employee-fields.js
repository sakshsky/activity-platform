export const personalGroups = [
  {title:'Personal information',fields:[['legal_name','Full legal name'],['preferred_name','Preferred name'],['date_of_birth','Date of birth','date'],['gender','Gender (optional)'],['nationality','Nationality'],['personal_email','Personal email','email'],['alternate_phone','Alternate phone','tel']]},
  {title:'Residential address',fields:[['current_address','Current street address','textarea'],['city','City'],['state','State / province'],['postal_code','Postal code'],['country','Country'],['permanent_address','Permanent address','textarea']]},
  {title:'Emergency contact',fields:[['emergency_name','Contact name'],['emergency_relationship','Relationship'],['emergency_phone','Contact phone','tel'],['emergency_alternate_phone','Alternate contact phone','tel']]},
  {title:'Education & skills',fields:[['qualification','Highest qualification'],['institution','Institution'],['graduation_year','Graduation year','number'],['skills','Professional skills','textarea']]},
]
export const employmentFields = [
  ['employee_code','Employee code'],['department','Department'],['job_title','Job title'],['manager','Reporting manager'],
  ['employment_type','Employment type','select',['full_time','part_time','contract','intern']],
  ['employment_status','Employment status','select',['active','probation','on_leave','exited']],
  ['joining_date','Joining date','date'],['probation_end_date','Probation end date','date'],['office','Office / branch'],
  ['work_mode','Work mode','select',['office','remote','hybrid']],
]
export function blankPersonal(){return Object.fromEntries(personalGroups.flatMap(g=>g.fields.map(f=>[f[0],''])))}
export function displayValue(value){return value == null || value === '' ? 'Not provided' : String(value).replaceAll('_',' ')}
